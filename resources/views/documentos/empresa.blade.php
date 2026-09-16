{{--
    La hoja de los documentos comerciales: cotización, nota de venta y orden de
    compra al distribuidor.

    Reproduce el papel que la empresa lleva años entregando, y esa forma no la
    elegimos nosotros: los dos logos —el propio y el de la marca que representa—,
    la rejilla que llena la hoja aunque sobren renglones, el bloque de
    condiciones abajo a la izquierda y la caja de totales a su derecha.

    ## Una hoja, tres documentos, bloques distintos

    El motor no sabe de tipos: recorre los bloques que el tipo declara. Los tres
    comparten esta hoja y su CSS, y no comparten casi ningún bloque, porque de
    verdad son papeles distintos — la cotización abre con un párrafo, la nota de
    venta con el número en un recuadro gris y la orden de compra con el nombre
    del distribuidor arriba a la izquierda.

    ## La rejilla se dibuja entera

    Como en el original: los renglones que sobran van con su raya. En un
    documento que se manda por correo y se imprime, la rejilla cerrada dice
    «aquí no se añadió nada después».
--}}
@php
    $t = fn ($v) => trim((string) ($v ?? ''));

    /* Dinero a peso entero, que es como lo cuadra Softland. */
    $plata = fn ($v) => number_format((float) $v, 0, ',', '.');

    /* Cantidad sin ceros de relleno: «1», no «1,00». */
    $cant = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');

    /* La UF sí lleva decimales: 12,12 no es 12. */
    $dec = fn ($v, $d = 2) => number_format((float) $v, $d, ',', '.');

    $dia = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '';

    /* El porcentaje de descuento de una línea.
       Softland guarda la **plata**, no el porcentaje, así que se saca del bruto
       de la línea. Sin esto la columna salía siempre en 0 % aunque el descuento
       estuviera aplicado, que es peor que no tener la columna. */
    $porcentaje = function ($l) {
        $bruto = ((float) ($l['cantidad'] ?? 0)) * ((float) ($l['precio'] ?? 0)) * ((float) ($l['equiv'] ?? 1));

        return $bruto > 0 ? round(((float) ($l['descuento'] ?? 0)) * 100 / $bruto) : 0;
    };

    $rut = function ($v) {
        $limpio = strtoupper(preg_replace('/[^0-9kK]/', '', (string) $v) ?? '');
        if (strlen($limpio) < 2) return (string) $v;
        return number_format((int) substr($limpio, 0, -1), 0, '', '.').'-'.substr($limpio, -1);
    };

    /* Los renglones de la rejilla, en páginas. */
    $porPagina = $renglones_por_pagina ?? 27;
    $paginas = array_chunk($lineas ?: [], $porPagina) ?: [[]];

    /* La rejilla parte los bloques en dos: lo de antes va en la primera página
       y lo de después en la última. Sale del orden declarado por el tipo, no de
       una lista escrita aquí. */
    $rejilla = collect($bloques)->first(fn ($b) => str_starts_with($b, 'detalle'));
    $corte = array_search($rejilla, $bloques, true);
    $antes = $corte === false ? $bloques : array_slice($bloques, 0, $corte);
    $despues = $corte === false ? [] : array_slice($bloques, $corte + 1);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 10mm 10mm 8mm; }

    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 8pt;
        line-height: 1.3;
        color: #000;
        margin: 0;
    }

    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }

    /* ------------------------------------------------------------- cabecera */

    .logos td { vertical-align: middle; padding: 0; }
    .logo-izq { max-height: 15mm; max-width: 62mm; }
    .logo-der { max-height: 15mm; max-width: 62mm; }

    /* Las dos barras grises que enmarcan la cabecera en el original. */
    .barra { height: 2.2mm; background: #b8b8b8; margin: 2.5mm 0; }

    .titulo-doc { text-align: center; font-size: 10pt; font-weight: bold; }
    .rotulo-empresa { text-align: center; font-size: 13pt; font-weight: bold; letter-spacing: .08em; }
    .contacto-empresa { text-align: center; font-size: 9.5pt; }

    .parrafo { margin: 2mm 0; text-align: justify; }

    /* ------------------------------------------------------------- receptor */

    .marco { border: .8pt solid #000; }
    .marco td { padding: .6mm 2mm; font-size: 8pt; }
    .marco .etiqueta { font-weight: bold; width: 24mm; white-space: nowrap; }
    .marco .etiqueta-d { font-weight: bold; text-align: right; width: 28mm; white-space: nowrap; }
    .subrayado { border-bottom: .4pt solid #000; }

    /* -------------------------------------------------------------- rejilla */

    .rejilla { margin-top: 2.5mm; }
    .rejilla th {
        border: .8pt solid #000;
        background: #e4e4e4;
        font-size: 7.5pt;
        font-weight: bold;
        text-align: center;
        padding: 1mm .8mm;
    }
    .rejilla td {
        border-left: .8pt solid #000;
        border-right: .8pt solid #000;
        padding: .2mm 1.2mm;
        font-size: 7.5pt;
        line-height: 1.2;
        height: 3.1mm;
    }
    .rejilla tr.cierra td { border-bottom: .8pt solid #000; }
    .rejilla .num { text-align: right; }
    .rejilla .centro { text-align: center; }

    /* La cabecera azul de la orden de compra, que es su seña de identidad.
       Y la letra un punto más chica: son ocho columnas y las descripciones de
       Softland son largas —«SISTEMA INVENTARIO CON FACTURACIÓN ERP ADVANCE»—,
       así que con la del resto cada renglón se parte en dos y la hoja se va a
       la segunda página por culpa del envoltorio. */
    .rejilla.oc td { font-size: 6.6pt; }
    .rejilla.oc th { background: #1f3864; color: #fff; border-color: #1f3864; font-size: 6.6pt; }

    /* ----------------------------------------------------- pie de la rejilla */

    .cierre { margin-top: 2mm; }
    .cierre > tbody > tr > td { vertical-align: top; }

    .condiciones { font-size: 7.5pt; }
    .condiciones .titulo { font-weight: bold; font-size: 7.5pt; }
    .condiciones ul { margin: .5mm 0 0; padding-left: 4mm; }
    .condiciones li { margin-bottom: .4mm; }

    .totales { border: .8pt solid #000; }
    .totales td { padding: .6mm 2mm; font-size: 8pt; border-bottom: .4pt solid #000; }
    .totales tr:last-child td { border-bottom: 0; }
    .totales .rotulo { font-weight: bold; }
    .totales .valor { text-align: right; width: 26mm; }

    .firma { text-align: center; margin-top: 5mm; font-size: 8pt; }
    .firma .nombre { margin-top: 8mm; }

    .pie-empresa { text-align: center; font-size: 8pt; font-weight: bold; padding-top: 1mm; }
    .pie-chico { text-align: center; font-size: 7.5pt; }

    .salto { page-break-after: always; }
</style>
</head>
<body>

@foreach ($paginas as $indice => $filas)
    @php $ultima = $indice === count($paginas) - 1; @endphp
    <div @class(['salto' => ! $ultima])>

        @if ($indice === 0)
            @foreach ($antes as $bloque)
                @includeIf('documentos.bloques.'.$bloque)
            @endforeach
        @endif

        @include('documentos.bloques.'.$rejilla, [
            'filas' => $filas,
            'desde' => $indice * $porPagina,
            'huecos' => max(0, $porPagina - count($filas)),
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
