{{--
    La hoja de los documentos legales: factura, boleta y nota de crédito.

    Es otra hoja que la de la cotización, y no por gusto. Ésta reproduce la
    representación impresa que el cliente lleva años recibiendo desde Softland —
    recuadro rojo con el folio, timbre abajo a la izquierda, acuse de recibo de
    la ley 19.983 al pie— y esa forma no la elegimos nosotros.

    Sigue siendo el mismo motor: el tipo declara sus bloques y aquí se recorren.
    La boleta no copiará esta plantilla, repetirá bloques.

    ## Lo que manda el tamaño

    Carta, no A4: es el papel en que Softland viene imprimiendo estos documentos
    y el que hay en la impresora. Y la rejilla de 27 renglones va dibujada
    entera aunque sobren, como en el original — en un documento tributario los
    renglones vacíos con su raya dicen «aquí no se añadió nada después».
--}}
@php
    $t = fn ($v) => trim((string) ($v ?? ''));

    /* Dinero a peso entero, que es como se factura y como lo cuadra Softland. */
    $plata = fn ($v) => number_format((float) $v, 0, ',', '.');

    /* Cantidad sin ceros de relleno: «1», no «1,00». */
    $cant = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');

    $dia = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '';

    /* El RUT con puntos, que es como va en el recuadro rojo. */
    $rut = function ($v) {
        $limpio = strtoupper(preg_replace('/[^0-9kK]/', '', (string) $v) ?? '');
        if (strlen($limpio) < 2) return (string) $v;
        $dv = substr($limpio, -1);
        $cuerpo = number_format((int) substr($limpio, 0, -1), 0, '', '.');
        return $cuerpo.'-'.$dv;
    };

    /* Los renglones de la rejilla, en páginas. La última lleva el cierre. */
    $porPagina = 27;
    $paginas = array_chunk($lineas ?: [], $porPagina) ?: [[]];

    /* La rejilla parte la lista de bloques en dos: lo que va antes se dibuja en
       la primera página y lo que va después, en la última. Se saca del propio
       orden declarado, no de una lista de nombres escrita aquí: así un bloque
       nuevo cae donde el tipo dijo, sin tocar esta hoja. */
    $corte = array_search('detalle_dte', $bloques, true);
    $antes = $corte === false ? $bloques : array_slice($bloques, 0, $corte);
    $despues = $corte === false ? [] : array_slice($bloques, $corte + 1);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 6mm 7mm 4mm; }

    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 7pt;
        line-height: 1.25;
        color: #000;
        margin: 0;
    }

    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }

    /* ------------------------------------------------------------- cabecera */

    .cabecera td { vertical-align: top; padding: 0; }
    .logo { max-height: 18mm; max-width: 42mm; }

    .emisor { text-align: center; font-size: 8pt; }
    .emisor .nombre { font-weight: bold; font-size: 8.5pt; line-height: 1.3; }
    .emisor .giro { font-size: 7pt; }
    .emisor .contacto { color: #0047b3; font-weight: bold; font-size: 7pt; }

    /* El recuadro rojo. Es la marca que hace reconocible el documento a un
       metro de distancia, y por eso es lo único en color de toda la hoja. */
    .folio {
        border: 2.5pt solid #e00000;
        color: #e00000;
        text-align: center;
        padding: 2.2mm 2mm;
        font-weight: bold;
    }
    .folio .rut { font-size: 10pt; }
    .folio .clase { font-size: 10pt; margin-top: 1.5mm; }
    .folio .numero { font-size: 11pt; margin-top: 2mm; }
    .oficina { text-align: right; font-size: 7.5pt; font-weight: bold; padding-top: 1mm; }

    /* ------------------------------------------------------------- receptor */

    .marco { border: .7pt solid #000; padding: 1mm 2mm; margin-top: 2mm; }
    .datos td { padding: .2mm 0; font-size: 7.5pt; }
    .datos .etiqueta { font-weight: bold; width: 21mm; white-space: nowrap; }
    .datos .etiqueta-d { font-weight: bold; text-align: right; width: 24mm; white-space: nowrap; padding-right: 2mm; }

    .referencias { border: .7pt solid #000; border-top: 0; padding: 1mm 2mm; font-size: 7.5pt; }
    .referencias .rotulo { font-weight: bold; font-style: italic; }

    /* -------------------------------------------------------------- detalle */

    .detalle { margin-top: 1.8mm; }
    .detalle th {
        border: .7pt solid #000;
        font-size: 7pt;
        font-weight: bold;
        text-align: center;
        padding: .5mm 1mm;
    }
    /* La altura del renglón está calibrada para que los 27 quepan con el
       cierre y el acuse en **una sola hoja**, que es como sale el original.
       Subirla medio milímetro manda el timbre a la página dos. */
    .detalle td {
        border-left: .7pt solid #000;
        border-right: .7pt solid #000;
        padding: .15mm 1.2mm;
        font-size: 7pt;
        line-height: 1.15;
        height: 2.9mm;
    }
    .detalle tr.ultima td { border-bottom: .7pt solid #000; }
    .detalle .n { text-align: center; color: #333; }
    .detalle .num { text-align: right; }
    .detalle .um { text-align: center; }

    /* --------------------------------------------------------------- cierre */

    .cierre { margin-top: 1.5mm; }
    .cierre > tbody > tr > td { vertical-align: bottom; }

    .timbre { text-align: center; }
    .timbre img { width: 58mm; }
    .timbre .rotulo { font-weight: bold; font-size: 8pt; padding-top: 1mm; }
    .timbre .resolucion { font-size: 6.5pt; padding-top: .8mm; }

    .totales { border: .7pt solid #000; }
    .totales td { padding: .5mm 2mm; font-size: 7.5pt; }
    .totales .rotulo { text-align: right; font-weight: bold; }
    .totales .valor { text-align: right; width: 24mm; }
    .totales .final { font-weight: bold; border-top: .7pt solid #000; }

    .son { border: .7pt solid #000; border-top: 0; padding: 1mm 2mm; font-size: 7pt; }
    .son .rotulo { font-weight: bold; }

    /* ----------------------------------------------------------------- pie */

    .acuse { margin-top: 1.5mm; border: .7pt solid #000; }
    .acuse td { padding: .9mm 2mm; font-size: 7pt; }
    .acuse .campo { border-bottom: .5pt solid #000; }
    .legal { font-size: 6pt; font-style: italic; padding: 1mm 2mm 0; line-height: 1.3; }
    .creado { text-align: center; font-size: 6pt; color: #444; padding-top: .6mm; }

    .salto { page-break-after: always; }
</style>
</head>
<body>

@foreach ($paginas as $indice => $renglones)
    @php $ultima = $indice === count($paginas) - 1; @endphp
    <div @class(['salto' => ! $ultima])>

        {{-- La cabecera y el recuadro del folio van en todas las páginas: cada
             hoja suelta tiene que poder identificarse sola. --}}
        @include('documentos.bloques.cabecera_dte', ['pagina' => $indice + 1, 'paginas' => count($paginas)])

        @if ($indice === 0)
            @foreach ($antes as $bloque)
                @includeIf('documentos.bloques.'.$bloque)
            @endforeach
        @endif

        @include('documentos.bloques.detalle_dte', [
            'renglones' => $renglones,
            'desde' => $indice * $porPagina,
            'huecos' => $porPagina - count($renglones),
        ])

        @if ($ultima)
            @foreach ($despues as $bloque)
                @includeIf('documentos.bloques.'.$bloque)
            @endforeach
        @endif
    </div>
@endforeach

</body>
</html>
