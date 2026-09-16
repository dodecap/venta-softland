{{--
    La cabecera del documento legal: quién emite, y el recuadro rojo.

    El recuadro es lo único en color de toda la hoja, y no es decoración: es lo
    que hace reconocible un documento tributario a un metro de distancia. Lleva
    las tres cosas por las que se busca — RUT del emisor, qué documento es y qué
    número tiene—, y debajo la oficina del SII que fiscaliza.

    Va en **todas** las páginas: una hoja suelta de una factura de tres tiene
    que poder identificarse sola.
--}}
<table class="cabecera">
    <tr>
        <td style="width: 26%;">
            @if ($logo)
                <img class="logo" src="{{ $logo }}" alt="{{ $identidad['nombre_comercial'] }}">
            @else
                <div class="emisor nombre" style="text-align: left;">{{ $identidad['nombre_comercial'] }}</div>
            @endif
        </td>
        <td style="width: 44%;" class="emisor">
            <div class="nombre">{{ $identidad['razon_social'] }}</div>
            @if ($identidad['giro'])
                <div class="giro">GIRO: {{ mb_strtoupper($identidad['giro'], 'UTF-8') }}</div>
            @endif
            <div class="giro">
                {{ mb_strtoupper(implode(' - ', array_filter([
                    $identidad['direccion'], $identidad['comuna'], $identidad['ciudad'],
                ])), 'UTF-8') }}
            </div>
            @if ($identidad['email'] || $identidad['web'])
                <div class="contacto">
                    @if ($identidad['email'])EMAIL: {{ $identidad['email'] }}@endif
                    @if ($identidad['email'] && $identidad['web']) - @endif
                    @if ($identidad['web'])WEB: {{ $identidad['web'] }}@endif
                </div>
            @endif
            @if ($identidad['fono'])
                <div class="giro"><b>Teléfono: {{ $identidad['fono'] }}</b></div>
            @endif
        </td>
        <td style="width: 30%; padding-left: 4mm;">
            <div class="folio">
                <div class="rut">R.U.T.: {{ $rut($identidad['rut']) }}</div>
                <div class="clase">{{ mb_strtoupper($tipo->titulo(), 'UTF-8') }}</div>
                <div class="numero">N° {{ $documento['folio'] ?? '' }}</div>
            </div>
            <div class="oficina">
                S.I.I. - {{ mb_strtoupper($identidad['sii_oficina'] ?: $identidad['ciudad'], 'UTF-8') }}
                @if ($paginas > 1)
                    <div style="font-weight: normal;">Página {{ $pagina }} de {{ $paginas }}</div>
                @endif
            </div>
        </td>
    </tr>
</table>
