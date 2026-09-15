<?php

namespace App\Console\Commands;

use App\Services\Dte\Caf;
use App\Services\Dte\Timbre;
use App\Services\Dte\TipoDte;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Paso 1 de la facturación electrónica: demostrar que sabemos timbrar.
 *
 * No emite nada, no escribe nada y no gasta un folio. Toma documentos que
 * Softland **ya emitió** y que el SII **ya aceptó**, vuelve a calcular su
 * timbre con nuestro código, y compara con el que quedó guardado.
 *
 * Son tres preguntas distintas, y conviene no confundirlas:
 *
 *   1. **firma** — tomando el `<DD>` guardado tal cual y firmándolo con la
 *      llave del CAF, ¿nos da el mismo `<FRMT>`? Esto prueba la criptografía:
 *      que leemos bien la llave, que usamos el algoritmo correcto y que no
 *      estropeamos los bytes por el camino. RSA con relleno PKCS#1 v1.5 es
 *      determinista, así que la misma entrada tiene que dar la misma salida.
 *      Si esto falla, todo lo demás da igual.
 *
 *   2. **construcción** — armando el `<DD>` desde los datos de origen
 *      (`iw_gsaen`, `iw_gmovi`), ¿nos da la misma cadena que escribió Softland?
 *      Esto prueba el mapeo: qué campo va en cada elemento, cómo se recorta,
 *      cómo se escribe el RUT. Es donde aparecen los errores aburridos.
 *
 *   3. **verificación** — ¿la firma guardada valida contra la llave pública del
 *      propio CAF? Es un control independiente de los dos anteriores: confirma
 *      que el CAF que leímos es realmente el que timbró ese documento.
 *
 * La marca de tiempo `TSTED` no se deriva de ningún dato: es el instante en que
 * Softland timbró. Para la prueba 2 se toma la del documento guardado, porque
 * lo que se está comprobando es el mapeo de los campos, no el reloj.
 *
 *   php artisan dte:verifica-timbre
 *   php artisan dte:verifica-timbre --tipo=33 --folio=234
 *   php artisan dte:verifica-timbre --todos
 *   php artisan dte:verifica-timbre --tipo=39 --base=NETDOMAIN --todos
 */
class DteVerificaTimbre extends Command
{
    protected $signature = 'dte:verifica-timbre
        {--tipo=33 : Tipo de DTE del SII (33, 34, 39, 41, 61)}
        {--folio= : Un folio concreto; si no se da, el último emitido}
        {--todos : Recorre todos los documentos de ese tipo que estén guardados}
        {--base= : Otra base de la instancia, solo lectura (por ejemplo NETDOMAIN)}';

    protected $description = 'Recalcula el timbre de documentos ya emitidos y lo compara con el guardado';

    public function handle(): int
    {
        $tipo = TipoDte::tryFrom((int) $this->option('tipo'));

        if (! $tipo) {
            $this->error('Tipo de DTE desconocido. Los que esta app maneja: 33, 34, 39, 41, 61.');

            return self::FAILURE;
        }

        $base = trim((string) $this->option('base'));

        $this->line("<options=bold>{$tipo->nombre()} ({$tipo->value})</>"
            .($base !== '' ? " — base {$base}, solo lectura" : ''));

        $lotes = Caf::lotes($tipo, $base ?: null);

        if ($lotes === []) {
            $this->warn('No hay ningún CAF cargado para este tipo. Sin folios autorizados no hay nada que timbrar.');

            return self::FAILURE;
        }

        $this->line(sprintf('  %d lote(s) de folios, del %s al %s',
            count($lotes),
            $lotes[0]->FolioD,
            end($lotes)->FolioH));

        $documentos = $this->documentos($tipo, $base);

        if ($documentos === []) {
            $this->warn('No hay documentos emitidos de este tipo contra los que contrastar.');

            return self::FAILURE;
        }

        $this->newLine();

        $bien = 0;
        $mal = 0;

        foreach ($documentos as $doc) {
            if ($this->comprueba($tipo, $doc, $base)) {
                $bien++;
            } else {
                $mal++;
            }
        }

        $this->newLine();

        if ($mal === 0) {
            $this->info("Los {$bien} documentos comprobados dan el timbre idéntico al de Softland.");

            return self::SUCCESS;
        }

        $this->error("{$mal} de ".($bien + $mal).' no coinciden.');

        return self::FAILURE;
    }

    /** Los documentos guardados contra los que contrastar. */
    private function documentos(TipoDte $tipo, string $base): array
    {
        $tabla = $base === '' ? 'softland.dte_archivos' : "{$base}.softland.dte_archivos";

        $q = DB::connection('softland')
            ->table($tabla)
            ->selectRaw('Tipo, NroInt, Folio, CAST(Archivo AS nvarchar(max)) AS xml')
            ->where('TipoDTE', $tipo->value)
            ->where('TipoXML', 'D')
            ->orderByDesc('Folio');

        if ($folio = $this->option('folio')) {
            $q->where('Folio', (int) $folio);
        } elseif (! $this->option('todos')) {
            $q->limit(1);
        }

        // Un mismo folio puede tener varias versiones del XML guardadas; basta una.
        $vistos = [];
        $salida = [];

        foreach ($q->get() as $fila) {
            $clave = $fila->Folio.'/'.$fila->Tipo.'/'.$fila->NroInt;
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $salida[] = $fila;
        }

        return $salida;
    }

    private function comprueba(TipoDte $tipo, object $doc, string $base): bool
    {
        $folio = (int) $doc->Folio;
        $etiqueta = sprintf('folio %-8s', $folio);

        try {
            $partes = Timbre::extraer((string) $doc->xml);

            if (! $partes) {
                $this->line("  {$etiqueta} <fg=yellow>sin timbre en el XML guardado</>");

                return false;
            }

            [$ddGuardado, $frmtGuardado] = $partes;

            // El folio que manda es el del propio timbre, no el de la columna.
            // `dte_archivos` conserva versiones anteriores del mismo documento
            // —en NETDOMAIN hay dos archivos con el mismo `NroInt` y folios
            // distintos—, y la columna `Folio` de una de ellas ya no dice nada.
            if (preg_match('#<F>(\d+)</F>#', $ddGuardado, $mf)) {
                $folio = (int) $mf[1];
                $etiqueta = sprintf('folio %-8s', $folio);
            }

            $caf = Caf::paraFolio($tipo, $folio, $base ?: null);
            $timbre = new Timbre($caf);

            // 1. Firma: los mismos bytes tienen que dar la misma firma.
            // 3. Verificación contra la llave pública del CAF.
            [$firma, $valida, $nota, $ddFirmado] = $this->analizaFirma($caf, $ddGuardado, $frmtGuardado);

            // 2. Construcción desde los datos de origen.
            [$construccion, $detalle] = $this->comparaConstruccion($tipo, $doc, $timbre, $ddFirmado, $base, $folio);

            $ok = $firma && $valida && $construccion;

            $this->line(sprintf('  %s  firma %s   verificación %s   construcción %s%s%s',
                $etiqueta,
                $this->marca($firma),
                $this->marca($valida),
                $this->marca($construccion),
                $nota === '' ? '' : "  <fg=yellow>{$nota}</>",
                $detalle === '' ? '' : "\n      {$detalle}"));

            return $ok;
        } catch (Throwable $e) {
            $this->line("  {$etiqueta} <fg=red>error</> ".$e->getMessage());

            return false;
        }
    }

    /**
     * Firma y verifica el `<DD>` guardado, distinguiendo un fallo real de una
     * copia recodificada.
     *
     * Hay documentos cuya copia en `dte_archivos` **no valida contra su propio
     * timbre**, y no porque el documento estuviera mal: el SII lo aceptó en su
     * momento. Lo que pasó es que la copia archivada se volvió a codificar a
     * UTF-8 en algún punto del camino a la base, y el DTE se firmó en
     * ISO-8859-1. Un acento pasó de un byte a dos y la firma dejó de calzar
     * sobre esos bytes.
     *
     * Se detecta devolviendo la cadena a ISO-8859-1 y probando otra vez. Si así
     * calza, el timbre siempre estuvo bien y lo que está recodificado es el
     * archivo. Conviene que el comando lo diga con todas sus letras, porque el
     * día que emitamos nosotros este mismo síntoma significaría algo muy
     * distinto: que estamos firmando en la codificación equivocada.
     *
     * Devuelve además **los bytes que realmente se firmaron**, que son contra
     * los que hay que comparar nuestra reconstrucción. Comparar contra la copia
     * recodificada daría una diferencia que no existe.
     *
     * @return array{0: bool, 1: bool, 2: string, 3: string}
     */
    private function analizaFirma(Caf $caf, string $dd, string $frmt): array
    {
        if ($caf->firmar($dd) === $frmt) {
            return [true, $caf->verificar($dd, $frmt), '', $dd];
        }

        if (mb_check_encoding($dd, 'UTF-8')) {
            $iso = mb_convert_encoding($dd, 'ISO-8859-1', 'UTF-8');

            if ($caf->firmar($iso) === $frmt) {
                return [true, $caf->verificar($iso, $frmt), 'la copia archivada está recodificada a UTF-8; el timbre es correcto', $iso];
            }
        }

        return [false, $caf->verificar($dd, $frmt), '', $dd];
    }

    /**
     * Arma el `<DD>` desde `iw_gsaen` / `iw_gmovi` y lo compara con el guardado.
     *
     * @return array{0: bool, 1: string}
     */
    private function comparaConstruccion(TipoDte $tipo, object $doc, Timbre $timbre, string $ddGuardado, string $base, int $folioTimbre): array
    {
        $pre = $base === '' ? 'softland' : "{$base}.softland";

        $cab = DB::connection('softland')->table("{$pre}.iw_gsaen")
            ->where('Tipo', $doc->Tipo)
            ->where('NroInt', $doc->NroInt)
            ->first();

        if (! $cab) {
            return [false, 'no está el documento en iw_gsaen'];
        }

        // Si el folio del timbre no es el que hoy tiene el documento, este
        // archivo es una versión anterior que quedó guardada. Reconstruirla no
        // prueba nada: los datos de origen ya cambiaron.
        if ((int) $cab->Folio !== $folioTimbre) {
            return [true, "versión anterior del archivo (el documento hoy es el folio {$cab->Folio}); no se compara"];
        }

        $linea = DB::connection('softland')->table("{$pre}.iw_gmovi")
            ->where('Tipo', $doc->Tipo)
            ->where('NroInt', $doc->NroInt)
            ->orderBy('Linea')
            ->first();

        $emisor = DB::connection('softland')->table("{$pre}.soempre")->first();

        // `iw_gsaen` **no** copia el nombre ni el RUT del cliente: guarda solo
        // su código en `CodAux` y los resuelve por join contra `cwtauxi`. Es al
        // revés de lo que uno esperaría de un documento tributario, que suele
        // congelar los datos del receptor, y explica por qué corregir la ficha
        // de un cliente cambia lo que muestra una factura vieja.
        $cliente = trim((string) ($cab->CodAux ?? '')) === ''
            ? null
            : DB::connection('softland')->table("{$pre}.cwtauxi")
                ->where('CodAux', $cab->CodAux)
                ->first();

        // El sello es el instante en que Softland timbró: no se deduce de los
        // datos, se toma del documento guardado.
        preg_match('#<TSTED>(.*?)</TSTED>#', $ddGuardado, $m);
        $sello = $m[1] ?? null;

        $nombre = $this->primerItem($pre, $tipo, $folioTimbre, (string) ($emisor->RutEmisor ?? ''), $linea);

        $ddNuestro = $timbre->datos(
            rutEmisor: (string) ($emisor->RutEmisor ?? ''),
            folio: $folioTimbre,
            fecha: substr((string) $cab->Fecha, 0, 10),
            rutReceptor: trim((string) ($cliente->RutAux ?? '')) ?: ($tipo->receptorAnonimo() ?? ''),
            razonSocial: trim((string) ($cliente->NomAux ?? '')) ?: ($tipo->razonSocialAnonima() ?? ''),
            monto: (int) round((float) $cab->Total),
            primerItem: $nombre,
            sello: $sello,
        );

        if ($ddNuestro === $ddGuardado) {
            return [true, ''];
        }

        return [false, $this->primeraDiferencia($ddGuardado, $ddNuestro)];
    }

    /**
     * El nombre del primer ítem, que es lo que va en `IT1`.
     *
     * Sale del **detalle del propio DTE** (`dte_docdet.NmbItem`), no del maestro
     * de productos. No es lo mismo, y dos facturas de INNOVAGES lo demuestran:
     * en el folio 62 el producto se llama «COMISION SOFTWARE» en `iw_tprod` y
     * la factura dice «Empresa adicional Cloud ERP anual». Quien factura puede
     * escribir la glosa que el cliente necesita leer, y el timbre sella esa
     * glosa, no la del catálogo.
     *
     * Al emitir nosotros no hay ambigüedad —`NmbItem` e `IT1` salen de la misma
     * cadena—, pero al reconstruir historia hay que leer lo que se escribió.
     */
    private function primerItem(string $pre, TipoDte $tipo, int $folio, string $rutEmisor, ?object $linea): string
    {
        $det = DB::connection('softland')->table("{$pre}.dte_docdet")
            ->where('RUTEmisor', $rutEmisor)
            ->where('TipoDTE', $tipo->value)
            ->where('Folio', $folio)
            ->orderBy('NroLinDet')
            ->first();

        if ($det && trim((string) $det->NmbItem) !== '') {
            return trim((string) $det->NmbItem);
        }

        if (! $linea) {
            return '';
        }

        $p = DB::connection('softland')->table("{$pre}.iw_tprod")
            ->where('CodProd', $linea->CodProd)
            ->first();

        return trim((string) ($p->DesProd ?? ''));
    }

    /** Dónde empiezan a diferir dos cadenas, con un poco de contexto a cada lado. */
    private function primeraDiferencia(string $esperado, string $nuestro): string
    {
        $n = min(strlen($esperado), strlen($nuestro));

        for ($i = 0; $i < $n; $i++) {
            if ($esperado[$i] !== $nuestro[$i]) {
                break;
            }
        }

        // Lo que se muestra tiene dos trampas para la consola. Una: es XML, y
        // Symfony lee `<algo>` como etiqueta de color, así que un `</IT1>`
        // cierra el estilo y rompe la línea. Otra: son bytes crudos, y aquí
        // justamente se comparan codificaciones — un byte 0xF3 suelto es ISO
        // inválido en UTF-8 y el terminal se come lo que venga detrás.
        //
        // Por eso todo lo que no sea ASCII imprimible se muestra como `\xNN`.
        // No es una concesión: la diferencia que se busca suele ser exactamente
        // esa, un acento escrito en una codificación distinta.
        $ventana = function (string $s) use ($i) {
            $trozo = substr($s, max(0, $i - 30), 70);
            $legible = preg_replace_callback(
                '/[^\x20-\x7E]/',
                fn ($m) => '\\x'.strtoupper(bin2hex($m[0])),
                $trozo
            ) ?? $trozo;

            return OutputFormatter::escape($legible);
        };

        return sprintf("difieren en el byte %d (Softland %d bytes, nosotros %d)\n",
                $i, strlen($esperado), strlen($nuestro))
            ."      Softland: …".$ventana($esperado)."…\n"
            ."      nosotros: …".$ventana($nuestro).'…';
    }

    private function marca(bool $ok): string
    {
        return $ok ? '<fg=green>ok</>' : '<fg=red>NO</>';
    }
}
