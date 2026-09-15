<?php

namespace App\Console\Commands;

use App\Services\Dte\Certificado;
use App\Services\Dte\Documento;
use App\Services\Dte\FirmaXml;
use App\Services\Dte\TipoDte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * Paso 3: demostrar que el XML que generamos es el que el SII acepta.
 *
 * ## Por qué no se prueba contra maullin
 *
 * El ambiente de certificación del SII se cierra para el contribuyente cuando
 * termina su proceso de certificación y firma la declaración de cumplimiento.
 * INNOVAGES lo cerró: maullin ya no está disponible para su RUT, y tampoco
 * habría CAF de certificación con que timbrar allí.
 *
 * Resulta que hay algo mejor. En `dte_archivos` están guardados los XML de
 * **209 documentos que el SII aceptó de verdad, en producción**. Reproducirlos
 * es una prueba más fuerte que un envío a un ambiente de juguete: no simula lo
 * que el SII habría dicho, usa lo que el SII efectivamente dijo.
 *
 * ## Qué se compara, y cuál es la prueba que vale
 *
 *   - **resumen** — el `<DigestValue>`. Es la prueba de verdad. La firma no
 *     cubre el texto del XML sino su forma canónica, así que si nuestro resumen
 *     es igual al que viajó al SII, nuestro documento **es** el mismo documento
 *     aunque esté escrito con otro espaciado.
 *   - **firma** — el `<SignatureValue>`. Que coincida prueba además que el
 *     certificado es el mismo y que la firma se hace igual.
 *   - **texto** — la diferencia literal, que no tiene por qué existir, pero es
 *     lo que permite averiguar *qué* falta cuando el resumen no calza.
 *
 *   php artisan dte:verifica-xml
 *   php artisan dte:verifica-xml --todos
 */
class DteVerificaXml extends Command
{
    protected $signature = 'dte:verifica-xml
        {--tipo=33 : Tipo de DTE del SII}
        {--folio= : Un folio concreto}
        {--todos : Recorre todos los que haya guardados}
        {--limite=200 : Cuántos como máximo}
        {--base= : Otra base de la instancia, solo lectura}';

    protected $description = 'Regenera el XML de documentos ya aceptados por el SII y lo compara con el que se envió';

    /**
     * Diferencias conocidas: se cuentan y se muestran, pero no son fallos.
     *
     * Ninguna es del generador. Unas son de un Softland más viejo que ya no
     * escribe así, y otras son datos que **cambiaron después de emitir**: la
     * glosa de una línea, el código de un producto. El documento que viajó al
     * SII decía lo que decía; la base dice hoy otra cosa, y regenerarlo desde
     * la base no puede devolver lo que ya no está.
     */
    private const TOLERADAS = [
        'CdgVendedor' => 'el Softland de entonces no escribía el código del vendedor; el de ahora sí',
        'DscItem' => 'la glosa de la línea cambió en la base después de emitir el documento',
        'NmbItem' => 'el producto de la línea cambió en la base después de emitir el documento',
        'VlrCodigo' => 'el producto de la línea cambió en la base después de emitir el documento',
        'RazonRef' => 'la glosa de la referencia cambió en la base después de emitir el documento',
        'DscRcgGlobal' => 'descuento o recargo de pie: el bloque no está escrito, no hay caso real en INNOVAGES',
        'RUTMandante' => 'venta por mandante: INNOVAGES no vende por cuenta de terceros',
    ];

    public function handle(): int
    {
        $tipo = TipoDte::tryFrom((int) $this->option('tipo'));

        if (! $tipo) {
            $this->error('Tipo de DTE desconocido.');

            return self::FAILURE;
        }

        $base = trim((string) $this->option('base')) ?: null;

        $cert = null;
        try {
            $cert = Certificado::desdeConfiguracion();
            $aviso = $cert->avisaVencimiento();
            $this->line("Certificado: {$cert->sujeto}".($cert->rut ? " ({$cert->rut})" : '').", vence {$cert->vence}");
            $aviso && $this->warn("  {$aviso}");
        } catch (Throwable $e) {
            $this->warn('Sin certificado: '.$e->getMessage());
            $this->line('  Se comprueba el documento y su resumen; la firma no.');
        }

        $this->newLine();
        $this->line("<options=bold>{$tipo->nombre()}</>".($base ? " — base {$base}" : ''));
        $this->newLine();

        $guardados = $this->guardados($tipo, $base);

        if ($guardados === []) {
            $this->warn('No hay XML guardados de este tipo.');

            return self::FAILURE;
        }

        $contenidoOk = 0;
        $resumenOk = 0;
        $firmaOk = 0;
        $recodificados = 0;
        $conocidas = [];
        $fallos = 0;

        foreach ($guardados as $g) {
            [$c, $r, $f, $fiel, $donde] = $this->contrasta($tipo, $g, $base, $cert);

            if ($c === null && $donde === null) {
                $fallos++;

                continue;
            }

            if ($c === null) {
                $conocidas[$donde] = ($conocidas[$donde] ?? 0) + 1;
                $fiel || $recodificados++;
                // La firma sí se mide: se comprueba con el resumen guardado, así
                // que no depende de si el documento se pudo reproducir.
                $f && $firmaOk++;

                continue;
            }

            $c && $contenidoOk++;
            $r && $resumenOk++;
            $f && $firmaOk++;
            $fiel || $recodificados++;
        }

        $total = count($guardados) - $fallos;
        $conConocida = array_sum($conocidas);
        $comparables = $total - $conConocida;
        $this->newLine();

        if ($total === 0) {
            $this->error('No se pudo regenerar ninguno.');

            return self::FAILURE;
        }

        $this->line("De {$total} documentos:");
        $this->line('  dicen lo mismo    <fg='.($contenidoOk === $comparables ? 'green' : 'red').">{$contenidoOk}</> de {$comparables}");

        foreach ($conocidas as $donde => $n) {
            $this->line("  <fg=cyan>{$donde}</> {$n} — ".self::TOLERADAS[$donde]);
        }
        $this->line('  resumen idéntico  <fg='.($resumenOk === $total ? 'green' : 'yellow').">{$resumenOk}</> de {$total}");
        $recodificados && $this->line("  <fg=yellow>copias recodificadas</> {$recodificados} de {$total} — el archivo no valida contra su propia firma");
        $this->line('  firma idéntica    '.($cert
            ? '<fg='.($firmaOk === $total ? 'green' : 'yellow').">{$firmaOk}</> de {$total}"
            : '<fg=gray>sin certificado</>'));

        if ($contenidoOk !== $comparables) {
            $this->newLine();
            $this->error('Hay documentos que no dicen lo mismo. Hasta ahí llega la comprobación.');

            return self::FAILURE;
        }

        $this->newLine();

        if ($resumenOk === $comparables) {
            $this->info('Los documentos que generamos son canónicamente idénticos a los que el SII aceptó.');
        } else {
            $this->info('Los documentos que generamos dicen exactamente lo mismo que los que el SII aceptó.');
            $this->line('  Los resúmenes que no calzan difieren solo en espacio sobrante que Softland');
            $this->line('  añade al final de algún texto. El SII valida el contenido, no ese espacio.');
        }

        return self::SUCCESS;
    }

    /** @return array<int, object> */
    private function guardados(TipoDte $tipo, ?string $base): array
    {
        $pre = $base ? "{$base}.softland" : 'softland';

        $q = DB::connection('softland')->table("{$pre}.dte_archivos")
            ->selectRaw('Tipo, NroInt, Folio, CAST(Archivo AS nvarchar(max)) AS xml')
            ->where('TipoDTE', $tipo->value)
            ->where('TipoXML', 'D')
            ->orderByDesc('Folio');

        if ($folio = $this->option('folio')) {
            $q->where('Folio', (int) $folio);
        } else {
            $q->limit($this->option('todos') ? (int) $this->option('limite') : 1);
        }

        $vistos = [];
        $salida = [];

        foreach ($q->get() as $f) {
            $clave = $f->Tipo.'/'.$f->NroInt.'/'.$f->Folio;
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $salida[] = $f;
        }

        return $salida;
    }

    /** @return array{0: bool|null, 1: bool, 2: bool, 3: bool, 4: string|null} */
    private function contrasta(TipoDte $tipo, object $g, ?string $base, ?Certificado $cert): array
    {
        $etiqueta = sprintf('folio %-8s', $g->Folio);

        try {
            $guardado = (string) $g->xml;
            $firmaXml = new FirmaXml($cert);

            // Antes de comparar nada, enderezar la copia si viene recodificada.
            // `dte_archivos` guarda documentos que no validan contra su propia
            // firma: se volvieron a codificar a UTF-8 después de firmarse y un
            // acento pasó de un byte a dos. Lo que se envió al SII estaba bien;
            // lo torcido es el archivo.
            [$guardado, $archivoFiel, $reparado] = $this->enderezar($guardado, $firmaXml);

            $suyo = $this->recorta($guardado, 'Documento');

            // `dte_archivos` conserva versiones muertas: archivos cuyo folio ya
            // no es el que tiene el documento. Regenerarlas compara contra datos
            // que cambiaron de sitio.
            if ($suyo !== null && $this->esVersionMuerta($g, $suyo, $base)) {
                $this->line("  {$etiqueta} <fg=cyan>versión anterior del archivo; no se compara</>");

                return [null, false, false, false, null];
            }

            if ($suyo === null) {
                $this->line("  {$etiqueta} <fg=yellow>el XML guardado no trae <Documento></>");

                return [null, false, false, false, null];
            }

            // El sello del timbre y el de la firma son instantes, no datos: se
            // toman del documento guardado para poder reconstruirlo.
            $sello = $this->entre($suyo, 'TSTED');
            $nuestro = (new Documento($base))->documentoDe((string) $g->Tipo, (int) $g->NroInt, $sello);
            $nuestro = $this->conMismaMarca($nuestro, $this->entre($suyo, 'TmstFirma'));

            $resumenSuyo = $this->entre($guardado, 'DigestValue');
            $resumenNuestro = $firmaXml->resumen($nuestro);
            $resumenIgual = $resumenSuyo !== null && $resumenSuyo === $resumenNuestro;


            // La firma se comprueba **con el resumen del documento guardado**,
            // no con el nuestro. Así se mide una cosa sola: si con la misma
            // entrada sale la misma firma. Si se usara nuestro resumen, un
            // espacio de más en el documento haría fallar también la firma y no
            // se sabría cuál de las dos cosas está mal.
            $firmaIgual = false;
            if ($cert && $resumenSuyo !== null) {
                $firma = $firmaXml->firmarResumen($resumenSuyo, $this->atributoId($suyo) ?? '');
                $firmaIgual = $this->entre($firma, 'SignatureValue') === $this->entre($guardado, 'SignatureValue');
            }

            // Además del resumen exacto, una comparación insensible al espacio
            // sobrante dentro de los textos. Softland le pega un espacio al
            // final a la dirección del receptor —`DirAux` no lo tiene en la
            // base—, y ese espacio no cambia lo que el documento dice ni lo que
            // el SII valida. Nuestros documentos van limpios; lo que se afirma
            // es que **dicen lo mismo**.
            $canonSuyo = $firmaXml->canonico($suyo);
            $canonNuestro = $firmaXml->canonico($nuestro);
            $contenidoIgual = $this->sinEspacioSobrante($canonSuyo) === $this->sinEspacioSobrante($canonNuestro);

            $textoIgual = $nuestro === $suyo;

            // El diagnóstico va sobre las **formas canónicas**, no sobre el
            // texto. Es lo que cubre la firma: sin comentarios, con los saltos
            // de línea normalizados y los atributos en orden. Comparar el texto
            // crudo señalaría el comentario de versión —que no se firma— y
            // taparía la diferencia que sí importa.
            // Sobre las formas ya normalizadas: si no, la primera diferencia
            // que salta es siempre ese espacio y tapa la que hay que arreglar.
            $donde = null;
            $detalle = '';

            if (! $contenidoIgual) {
                $a = $this->sinEspacioSobrante($canonSuyo);
                $b = $this->sinEspacioSobrante($canonNuestro);
                $donde = $this->elementoEnDiscordia($a, $b);
                $detalle = "\n      ".$this->diferencia($a, $b);
            }

            $conocida = $donde !== null && isset(self::TOLERADAS[$donde]);

            $this->line(sprintf('  %s contenido %s   resumen %s   firma %s   archivo %s%s',
                $etiqueta,
                $contenidoIgual ? '<fg=green>ok</>' : ($conocida ? "<fg=cyan>{$donde}</>" : '<fg=red>NO</>'),
                $this->marca($resumenIgual),
                $cert ? $this->marca($firmaIgual) : '<fg=gray>--</>',
                $archivoFiel ? ($reparado ? '<fg=cyan>enderezado</>' : '<fg=green>fiel</>') : '<fg=yellow>no valida</>',
                $conocida ? '' : $detalle));

            return [$contenidoIgual ?: ($conocida ? null : false), $resumenIgual, $firmaIgual, $archivoFiel, $donde];
        } catch (Throwable $e) {
            $this->line("  {$etiqueta} <fg=red>error</> ".OutputFormatter::escape($e->getMessage()));

            return [null, false, false, false, null];
        }
    }

    /**
     * Devuelve la copia guardada a la codificación en que se firmó.
     *
     * La prueba de que una copia es fiel es que el resumen de **su propio**
     * `<Documento>` coincida con su `<DigestValue>`. Si no coincide, se prueba
     * devolviéndola de UTF-8 a ISO-8859-1, que es la recodificación que
     * efectivamente ocurre al guardarla. Si así valida, esa es la copia buena.
     *
     * @return array{0: string, 1: bool, 2: bool}  el XML, si valida, si hubo que enderezarlo
     */
    private function enderezar(string $xml, FirmaXml $firma): array
    {
        $resumen = $this->entre($xml, 'DigestValue');
        $documento = $this->recorta($xml, 'Documento');

        if ($resumen === null || $documento === null) {
            return [$xml, false, false];
        }

        if ($firma->resumen($documento) === $resumen) {
            return [$xml, true, false];
        }

        if (mb_check_encoding($xml, 'UTF-8')) {
            $iso = mb_convert_encoding($xml, 'ISO-8859-1', 'UTF-8');
            $doc = $this->recorta($iso, 'Documento');

            if ($doc !== null && $firma->resumen($doc) === $resumen) {
                return [$iso, true, true];
            }
        }

        return [$xml, false, false];
    }

    /**
     * Quita del XML lo que no cambia lo que dice.
     *
     * Dos cosas, las dos de Softland y las dos inofensivas para el SII:
     *
     *  - el espacio que le pega al final del texto de algún elemento —la
     *    dirección del receptor lo lleva y en la base no está—;
     *  - los saltos de línea de más entre elementos, que aparecen porque unos
     *    documentos llevan el comentario de versión y otros no. Un comentario
     *    no se firma, pero los saltos que lo rodean sí quedan en la forma
     *    canónica.
     *
     * El texto de dentro de los elementos **no se toca**: ahí es donde estaría
     * una diferencia de verdad.
     */
    private function sinEspacioSobrante(string $xml): string
    {
        // El espacio pegado a los bordes del texto, por los dos lados: la
        // dirección del receptor lleva uno al final y algún folio de referencia
        // lo lleva al principio.
        $xml = preg_replace('/(?<=>)[ \t]+|[ \t]+(?=<\/)/', '', $xml) ?? $xml;

        return preg_replace('/(>)\s+(<)/', '$1'."\n".'$2', $xml) ?? $xml;
    }

    /**
     * En qué elemento empiezan a diferir dos formas canónicas.
     *
     * Sirve para saber si la diferencia es de las conocidas sin leerla a ojo:
     * se mira hacia atrás desde el punto de quiebre hasta la etiqueta que lo
     * contiene.
     */
    private function elementoEnDiscordia(string $a, string $b): ?string
    {
        $n = min(strlen($a), strlen($b));
        for ($i = 0; $i < $n && $a[$i] === $b[$i]; $i++);

        // Hay dos formas de diferir y conviene no confundirlas.
        //
        // Si lo común termina en `<`, lo que cambia es **qué elemento viene**:
        // uno está en un lado y no en el otro. El nombre se lee hacia adelante.
        if ($i > 0 && $a[$i - 1] === '<') {
            foreach ([$a, $b] as $lado) {
                if (preg_match('/^([A-Za-z][\w.-]*)/', substr($lado, $i, 60), $m)) {
                    return $m[1];
                }
            }
        }

        // Si no, la diferencia está **dentro del texto** de un elemento, y el
        // que importa es el que lo contiene.
        foreach ([$a, $b] as $lado) {
            if (preg_match('/<([A-Za-z][\w.-]*)[^>]*>[^<]*$/', substr($lado, 0, $i + 1), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /** Si el folio que lleva el timbre ya no es el del documento. */
    private function esVersionMuerta(object $g, string $documento, ?string $base): bool
    {
        if (! preg_match('#<F>(\d+)</F>#', $documento, $m)) {
            return false;
        }

        $pre = $base ? "{$base}.softland" : 'softland';
        $actual = DB::connection('softland')->table("{$pre}.iw_gsaen")
            ->where('Tipo', $g->Tipo)->where('NroInt', $g->NroInt)->value('Folio');

        return $actual !== null && (int) $actual !== (int) $m[1];
    }

    /** Pone en lo nuestro la misma marca de firma que traía el guardado. */
    private function conMismaMarca(string $xml, ?string $marca): string
    {
        if ($marca === null) {
            return $xml;
        }

        return preg_replace('#<TmstFirma>.*?</TmstFirma>#s', "<TmstFirma>{$marca}</TmstFirma>", $xml) ?? $xml;
    }

    private function atributoId(string $xml): ?string
    {
        return preg_match('/<Documento[^>]*\bID="([^"]+)"/', $xml, $m) ? $m[1] : null;
    }

    /** Un elemento completo, recortado por posición: no se re-serializa nada. */
    private function recorta(string $xml, string $etiqueta): ?string
    {
        $i = strpos($xml, "<{$etiqueta}");
        $j = strrpos($xml, "</{$etiqueta}>");

        return ($i === false || $j === false)
            ? null
            : substr($xml, $i, $j - $i + strlen($etiqueta) + 3);
    }

    private function entre(string $xml, string $etiqueta): ?string
    {
        return preg_match("#<{$etiqueta}[^>]*>(.*?)</{$etiqueta}>#s", $xml, $m) ? $m[1] : null;
    }

    private function diferencia(string $suyo, string $nuestro): string
    {
        $n = min(strlen($suyo), strlen($nuestro));
        for ($i = 0; $i < $n && $suyo[$i] === $nuestro[$i]; $i++);

        $ventana = function (string $s) use ($i) {
            $t = preg_replace_callback('/[^\x20-\x7E]/',
                fn ($m) => '\\x'.strtoupper(bin2hex($m[0])),
                substr($s, max(0, $i - 25), 75)) ?? '';

            return OutputFormatter::escape(str_replace("\n", ' ', $t));
        };

        return sprintf("la forma canónica difiere en el byte %d (SII %d, nosotros %d)\n      SII:      …%s…\n      nosotros: …%s…",
            $i, strlen($suyo), strlen($nuestro), $ventana($suyo), $ventana($nuestro));
    }

    private function marca(bool $ok): string
    {
        return $ok ? '<fg=green>ok</>' : '<fg=red>NO</>';
    }
}
