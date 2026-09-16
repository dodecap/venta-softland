<?php

namespace App\Console\Commands;

use App\Services\Dte\Certificado;
use App\Services\Dte\FirmaXml;
use App\Services\Dte\Sobre;
use App\Services\Dte\TipoDte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Comprueba el sobre sin mandarlo: lo regenera y lo compara con los que el SII
 * ya aceptó.
 *
 * Mismo método que `dte:verifica-xml` y por la misma razón —maullin está
 * cerrado para INNOVAGES—, un piso más arriba: allí se comprobaba el documento,
 * aquí el envío que lo contiene.
 *
 * Lo que vale es el **resumen** del `<SetDTE>`. La firma del sobre cubre su
 * forma canónica; si nuestro resumen es el mismo que el del sobre que viajó, el
 * sobre es el mismo aunque esté escrito con otro espaciado.
 *
 * ## Se mide el sobre, no el documento
 *
 * Dentro de nuestro sobre va **el `<DTE>` original**, el que viajó al SII, no
 * uno regenerado. A propósito: el documento ya tiene su prueba en
 * `dte:verifica-xml`, y mezclarlas haría que cualquier diferencia de un
 * documento —un dato que cambió en la base después de emitir— pareciera un
 * fallo del sobre. Aquí se comprueba una cosa sola: la carátula y el envoltorio.
 *
 *   php artisan dte:verifica-sobre
 *   php artisan dte:verifica-sobre --todos
 */
class DteVerificaSobre extends Command
{
    protected $signature = 'dte:verifica-sobre
        {--folio= : Un folio concreto}
        {--tipo=33 : Tipo de DTE del SII}
        {--todos : Recorre todos los sobres guardados}
        {--limite=50 : Cuántos como máximo}
        {--base= : Otra base de la instancia, solo lectura}';

    protected $description = 'Regenera el sobre de envíos ya aceptados por el SII y lo compara con el que se envió';

    public function handle(): int
    {
        $tipo = TipoDte::tryFrom((int) $this->option('tipo'));

        if (! $tipo) {
            $this->error('Tipo de DTE desconocido.');

            return self::FAILURE;
        }

        $base = trim((string) $this->option('base')) ?: null;

        try {
            $cert = Certificado::desdeConfiguracion();
        } catch (Throwable $e) {
            $this->error('Sin certificado no se puede armar el sobre: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("Certificado: {$cert->sujeto}".($cert->rut ? " ({$cert->rut})" : '').", vence {$cert->vence}");
        $this->line("Quien envía: <options=bold>{$cert->rut}</> — es el RUT que va en <RutEnvia>, no el de la empresa");
        $this->newLine();

        $guardados = $this->guardados($tipo, $base);

        if ($guardados === []) {
            $this->warn('No hay sobres guardados de este tipo.');

            return self::FAILURE;
        }

        $sobre = new Sobre($cert, $base);
        $firma = new FirmaXml($cert, [
            '' => 'http://www.sii.cl/SiiDte',
            'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        ]);
        $iguales = 0;
        $firmasIguales = 0;
        $fallos = 0;
        $muertos = 0;

        $recodificados = 0;

        foreach ($guardados as $g) {
            [$xml, $recodificado] = $this->enderezar($g->xml, new FirmaXml);
            $recodificado && $recodificados++;
            $original = $this->setDteDe($xml);
            $resumenGuardado = $this->valor($this->firmaDelSobre($xml), 'DigestValue');

            if ($original === null || $resumenGuardado === null) {
                $this->line("  folio {$g->Folio}: el sobre guardado no se pudo leer");
                $fallos++;

                continue;
            }

            try {
                $nuestro = $sobre->armar(
                    [[
                        'tipo' => $g->Tipo,
                        'nroInt' => (int) $g->NroInt,
                        'xml' => $this->dteDe($xml),
                    ]],
                    $this->selloEnvio($original),
                );
            } catch (Throwable $e) {
                $this->line("  folio {$g->Folio}: <fg=red>{$e->getMessage()}</>");
                $fallos++;

                continue;
            }

            // `dte_archivos` conserva versiones anteriores del mismo documento:
            // filas cuyo folio ya no es el que lleva el documento. Regenerarlas
            // devuelve el folio de ahora, que no es el de la fila, y compararlas
            // sería comparar dos documentos distintos.
            if ((int) $g->Folio !== ($nuestro['folios'][0] ?? 0)) {
                $muertos++;

                continue;
            }

            $mismoResumen = $nuestro['resumen'] === $resumenGuardado;
            $mismoResumen && $iguales++;

            // La firma se comprueba con el resumen **guardado**, no con el
            // nuestro: así se mide una sola cosa —si con la misma entrada sale
            // la misma firma— y no se confunde un fallo de firma con una
            // diferencia de contenido.
            $nuestraFirma = $this->valor($firma->firmarResumen($resumenGuardado, $this->idDe($original) ?? ''), 'SignatureValue');
            $mismaFirma = $nuestraFirma !== null
                && $nuestraFirma === $this->valor($this->firmaDelSobre($xml), 'SignatureValue');
            $mismaFirma && $firmasIguales++;

            $marca = $mismoResumen ? '<fg=green>=</>' : '<fg=red>≠</>';
            $this->line("  folio {$g->Folio}  {$marca} resumen   ".($mismaFirma ? '<fg=green>=</>' : '<fg=red>≠</>').' firma');

            if (! $mismoResumen) {
                // Sobre la forma canónica, que es lo que se firma: comparar el
                // texto crudo señalaría el comentario de versión, que la
                // canonicalización quita y que por tanto no cambia nada.
                $this->diferencia(
                    $firma->canonico($original),
                    $firma->canonico($this->setDteDe($nuestro['xml']) ?? '<SetDTE/>'),
                );
            }
        }

        $total = count($guardados) - $muertos;
        $this->newLine();
        $this->line("Sobres comparados: {$total}");
        $this->line("  dicen lo mismo: <options=bold>{$iguales} de {$total}</>");
        $this->line("  firma idéntica: <options=bold>{$firmasIguales} de {$total}</>");
        $fallos && $this->warn("  no se pudieron comparar: {$fallos}");
        $recodificados && $this->line("  guardados recodificados a UTF-8 después de firmar: {$recodificados}");
        $muertos && $this->line("  versiones muertas del archivo, saltadas: {$muertos}");

        return $iguales === $total ? self::SUCCESS : self::FAILURE;
    }

    /** Dónde empieza a diferir, que es lo único que sirve para arreglarlo. */
    private function diferencia(string $suyo, string $nuestro): void
    {
        $n = min(strlen($suyo), strlen($nuestro));

        for ($i = 0; $i < $n && $suyo[$i] === $nuestro[$i]; $i++);

        $desde = max(0, $i - 40);
        $ver = fn (string $s) => preg_replace('/[^\x20-\x7e]/', '·', substr($s, $desde, 120));
        $this->line('      suyo:    '.$ver($suyo));
        $this->line('      nuestro: '.$ver($nuestro));
    }

    /** @return list<object> */
    private function guardados(TipoDte $tipo, ?string $base): array
    {
        $pre = $base ? "{$base}.softland" : 'softland';
        $q = DB::connection('softland')->table("{$pre}.dte_archivos")
            ->where('TipoXML', 'SS')
            ->where('TipoDTE', $tipo->value)
            ->orderByDesc('ID_Archivo')
            ->selectRaw('Tipo, NroInt, Folio, CAST(Archivo AS varchar(max)) AS xml');

        if ($folio = $this->option('folio')) {
            return $q->where('Folio', (int) $folio)->get()->all();
        }

        return $q->limit($this->option('todos') ? (int) $this->option('limite') : 1)->get()->all();
    }

    private function setDteDe(string $xml): ?string
    {
        $i = strpos($xml, '<SetDTE ');
        $j = strrpos($xml, '</SetDTE>');

        return $i === false || $j === false ? null : substr($xml, $i, $j - $i + strlen('</SetDTE>'));
    }

    /**
     * Deshace la recodificación del archivo, si la hubo.
     *
     * Lo que está en `dte_archivos` no siempre son los bytes que se firmaron: en
     * unos casos el archivo se guardó pasado a UTF-8, y entonces un acento ocupa
     * dos bytes donde ocupaba uno y ningún resumen calza.
     *
     * El ancla para decidirlo es el resumen **del documento**, que viene
     * guardado en el propio archivo y es independiente de lo que se está
     * midiendo aquí. Elegir la versión que hace calzar el resumen del sobre
     * sería dar por buena la conclusión.
     *
     * @return array{0:string, 1:bool}
     */
    private function enderezar(string $xml, FirmaXml $firma): array
    {
        $resumen = $this->valor($xml, 'DigestValue');
        $documento = $this->recorta($xml, 'Documento');

        if ($resumen === null || $documento === null || $firma->resumen($documento) === $resumen) {
            return [$xml, false];
        }

        if (mb_check_encoding($xml, 'UTF-8')) {
            $iso = mb_convert_encoding($xml, 'ISO-8859-1', 'UTF-8');
            $doc = $this->recorta($iso, 'Documento');

            if ($doc !== null && $firma->resumen($doc) === $resumen) {
                return [$iso, true];
            }
        }

        return [$xml, false];
    }

    private function recorta(string $xml, string $elemento): ?string
    {
        $i = strpos($xml, "<{$elemento} ");
        $j = strpos($xml, "</{$elemento}>");

        return $i === false || $j === false ? null : substr($xml, $i, $j - $i + strlen($elemento) + 3);
    }

    /** El `<DTE>` del sobre guardado, byte por byte como viajó. */
    private function dteDe(string $xml): ?string
    {
        $i = strpos($xml, '<DTE ');
        $j = strrpos($xml, '</DTE>');

        return $i === false || $j === false ? null : substr($xml, $i, $j - $i + strlen('</DTE>'));
    }

    /**
     * La firma **del sobre**, que es la última del archivo.
     *
     * Un sobre lleva dos firmas y la del documento va primero. Buscar
     * `<DigestValue>` a secas devuelve la del documento, y entonces nada calza
     * nunca: se está comparando el resumen de una cosa con el de otra.
     */
    private function firmaDelSobre(string $xml): string
    {
        $fin = strrpos($xml, '</SetDTE>');

        return $fin === false ? $xml : substr($xml, $fin);
    }

    private function idDe(string $setDte): ?string
    {
        return preg_match('/<SetDTE ID="([^"]+)"/', $setDte, $m) ? $m[1] : null;
    }

    private function selloEnvio(string $setDte): ?string
    {
        return $this->valor($setDte, 'TmstFirmaEnv');
    }

    private function valor(string $xml, string $elemento): ?string
    {
        return preg_match('#<'.$elemento.'>(.*?)</'.$elemento.'>#s', $xml, $m) ? trim($m[1]) : null;
    }
}
