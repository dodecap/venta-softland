{{--
    La hoja. Una sola para todos los tipos de documento.

    Dompdf es un motor de CSS 2.1: no entiende flex ni grid. Lo que aquí parezca
    anticuado — tablas para maquetar, medidas en milímetros, estilos escritos a
    mano — está así porque es lo único que dibuja.

    ## Cómo se repiten cabecera y pie

    `position: fixed` sale del flujo y se dibuja **en todas las páginas**. Va
    colocado dentro del margen de `@page`, con desplazamiento negativo: por eso
    los márgenes de arriba y de abajo son grandes y los valores de `top` y
    `bottom` son negativos. Si la cabecera creciera más que el margen declarado,
    pisaría el contenido — por eso su alto está fijado en milímetros y no
    depende del largo de la razón social.
--}}
@php
    $color = $identidad['color'];
    $simbolo = $moneda_simbolo;

    /* Dinero siempre a peso entero: es como lo guarda y lo cuadra Softland. */
    $plata = fn ($v) => $simbolo.' '.number_format((float) $v, 0, ',', '.');

    /* Cantidad sin ceros de relleno: «1», no «1,00». */
    $cant = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');

    /* Los valores en moneda de origen sí llevan decimales: 56,75 no es 57.
       No se llama `$uf` a propósito: esa es la UF del día, que viene en el
       contexto, y el nombre repetido la borraría. */
    $decimal = fn ($v) => number_format((float) $v, 2, ',', '.');

    $dia = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d-m-Y') : '';

    $numero = $documento['numero'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 30mm 15mm 20mm; }

    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 9pt;
        line-height: 1.34;
        color: #1a1a22;
        margin: 0;
    }

    /* ------------------------------------------------- cabecera y pie fijos */

    .cabecera {
        position: fixed;
        top: -23mm; left: 0; right: 0;
        height: 20mm;
    }
    .cabecera td { vertical-align: top; }
    .logo { max-height: 14mm; }
    .emisor-nombre { font-size: 13pt; font-weight: bold; color: {{ $color }}; }
    .emisor-rut { font-size: 8pt; color: #6e6e7d; }

    .titulo-doc {
        font-size: 13pt;
        font-weight: bold;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: {{ $color }};
    }
    .numero-doc { font-size: 15pt; font-weight: bold; }
    .fecha-doc { font-size: 8.5pt; color: #6e6e7d; }

    .pie {
        position: fixed;
        bottom: -14mm; left: 0; right: 0;
        height: 11mm;
        font-size: 7.5pt;
        color: #6e6e7d;
    }
    .pie .filete { height: 2.5pt; background: {{ $color }}; margin-bottom: 4pt; }

    /* -------------------------------------------------------------- bloques */

    h2 {
        font-size: 8pt;
        font-weight: bold;
        letter-spacing: .1em;
        text-transform: uppercase;
        color: {{ $color }};
        margin: 0 0 4pt;
        padding-bottom: 2pt;
        border-bottom: .5pt solid #d8d6e2;
    }

    table { width: 100%; border-collapse: collapse; }
    .der { text-align: right; white-space: nowrap; }
    .tenue { color: #6e6e7d; }

    .ficha td { padding: 1pt 0; vertical-align: top; }
    .ficha .rot { color: #6e6e7d; white-space: nowrap; padding-right: 10pt; }

    /* ------------------------------------------------------------- detalle */

    .detalle { margin-top: 3pt; }
    .detalle th {
        font-size: 7.5pt;
        font-weight: bold;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: {{ $color }};
        text-align: left;
        padding: 5pt 5pt;
        border-bottom: 1.2pt solid {{ $color }};
        border-top: .5pt solid #d8d6e2;
    }
    .detalle td { padding: 3.5pt 5pt; border-bottom: .5pt solid #e8e6ef; vertical-align: top; }
    .detalle .cod { font-size: 7.5pt; color: #6e6e7d; }
    .detalle .desc { word-wrap: break-word; }

    /* --------------------------------------------------------------- cierre */

    .cierre td { vertical-align: top; }

    .totales td { padding: 2.5pt 0; }
    .totales .rot { color: #4a4a58; }
    .totales .final td {
        border-top: 1.2pt solid {{ $color }};
        padding-top: 4pt;
        font-weight: bold;
        font-size: 12pt;
        color: {{ $color }};
    }

    .condiciones { font-size: 8pt; color: #4a4a58; }
    .condiciones div { margin-bottom: 2pt; }

    .nota { border-left: 2pt solid #d8d6e2; padding: 3pt 0 3pt 7pt; font-size: 8.5pt; color: #4a4a58; }

    .firma { font-size: 8.5pt; }
    .firma .nombre { font-weight: bold; font-size: 10pt; }

    .sello-estado {
        border: .8pt solid #a3521a;
        color: #a3521a;
        font-size: 8pt;
        font-weight: bold;
        letter-spacing: .08em;
        text-transform: uppercase;
        padding: 4pt 8pt;
        margin-bottom: 8pt;
    }

    /* Una fila no se parte entre dos páginas, y los totales no se quedan solos
       en una hoja vacía: se van enteros con las condiciones. */
    tr, .no-partir { page-break-inside: avoid; }
</style>
</head>
<body>

{{-- ══════════════════════════════════════════════════════════ cabecera --}}
<table class="cabecera">
    <tr>
        <td style="width:55%;">
            @if ($logo)
                <img class="logo" src="{{ $logo }}" alt="">
            @else
                {{-- Sin logo el documento sale igual: una instalación nueva
                     funciona el día uno sin configurar nada. --}}
                <div class="emisor-nombre">{{ $identidad['nombre_comercial'] ?: $identidad['razon_social'] }}</div>
            @endif
            @if ($identidad['rut'])
                <div class="emisor-rut">RUT {{ $identidad['rut'] }}</div>
            @endif
        </td>
        <td style="width:45%; text-align:right;">
            <div class="titulo-doc">{{ $tipo->titulo() }}</div>
            @if ($numero)
                <div class="numero-doc">N° {{ $numero }}</div>
            @endif
            <div class="fecha-doc">{{ $dia($documento['fecha'] ?? null) }}</div>
        </td>
    </tr>
</table>

{{-- ═════════════════════════════════════════════════════════════ pie --}}
<div class="pie">
    <div class="filete"></div>
    @php
        $direccion = array_filter([
            $identidad['direccion'],
            $identidad['comuna'] ?: $identidad['ciudad'],
        ]);
        // Ojo con el nombre: los bloques se incluyen más abajo y heredan las
        // variables locales de esta plantilla. Una llamada `$contacto` aquí
        // pisaría en silencio el contacto del cliente.
        $contactoEmisor = array_filter([
            $identidad['fono'] ? 'Fono '.$identidad['fono'] : '',
            $identidad['email'],
            $identidad['web'],
        ]);
    @endphp
    <div>
        <strong>{{ $identidad['razon_social'] }}</strong>@if ($direccion) · {{ implode(', ', $direccion) }}@endif
        @if ($contactoEmisor) · {{ implode(' · ', $contactoEmisor) }}@endif
    </div>
    @if ($identidad['pie_documento'])
        <div>{{ $identidad['pie_documento'] }}</div>
    @endif
</div>

{{-- ════════════════════════════════════════════════════════ contenido --}}
@if (! empty($sello))
    <div class="sello-estado">{{ $sello }}</div>
@endif

@foreach ($bloques as $bloque)
    @includeIf('documentos.bloques.'.$bloque)
@endforeach

</body>
</html>
