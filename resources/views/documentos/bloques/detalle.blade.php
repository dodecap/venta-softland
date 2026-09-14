{{--
    Lo que se vende. Es el único bloque que puede pasar de una página, y por eso
    es el que decide el paginado:

    - el `<thead>` lo repite dompdf solo al partir la tabla;
    - cada `<tr>` lleva `page-break-inside: avoid` (está en la hoja base), así
      que una línea no se corta a la mitad;
    - la letra **no se achica** para que quepa todo. Si hay sesenta líneas hay
      tres páginas. 8,5 pt es el piso legible impreso.

    Las columnas las decide el motor (`Motor::columnas`): la de conversión
    aparece sola cuando alguna línea viene en otra moneda que el documento, y el
    código y la unidad se van cuando la empresa vende servicios.
--}}
<h2>Detalle</h2>
<table class="detalle">
    <thead>
        <tr>
            @if ($columnas['codigo'])
                <th style="width:13%;">Código</th>
            @endif
            <th style="width:{{ $columnas['ancho_descripcion'] }};">Descripción</th>
            <th class="der" style="width:7%;">Cant.</th>
            @if ($columnas['unidad'])
                <th style="width:6%;">Un.</th>
            @endif
            @if ($columnas['conversion'])
                <th class="der" style="width:11%;">Valor {{ $moneda_producto ?? 'UF' }}</th>
            @endif
            <th class="der" style="width:15%;">Unitario</th>
            @if ($columnas['descuento'])
                <th class="der" style="width:11%;">Dscto.</th>
            @endif
            <th class="der" style="width:16%;">Subtotal</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lineas as $l)
            @php
                $equiv = ((float) ($l['equiv'] ?? 0)) ?: 1;
                $unitario = ((float) $l['precio']) * $equiv;

                /*
                 * `*` es el producto comodín de Softland: la línea no vende
                 * nada, sólo dice algo. Mostrarle código, unidad y «$ 0» la
                 * disfraza de artículo gratis, que es lo único que un cliente
                 * no debería poder leer en una cotización.
                 */
                $comentario = trim((string) $l['producto']) === '*';
            @endphp
            <tr>
                @if ($columnas['codigo'])
                    <td class="cod">{{ $comentario ? '' : $l['producto'] }}</td>
                @endif
                <td class="desc">
                    {{ $l['detalle'] ?: ($nombres[$l['producto']] ?? $l['producto']) }}
                </td>
                <td class="der">{{ $comentario ? '' : $cant($l['cantidad']) }}</td>
                @if ($columnas['unidad'])
                    <td class="tenue">{{ $comentario ? '' : ($l['unidad'] ?? '') }}</td>
                @endif
                @if ($columnas['conversion'])
                    {{-- El precio de la línea está en la moneda del producto y
                         `equiv` es el factor a la del documento. Un tercio de
                         las líneas de INNOVAGES lo tiene distinto de 1. --}}
                    <td class="der">{{ ! $comentario && $equiv != 1 ? $decimal($l['precio']) : '' }}</td>
                @endif
                <td class="der">{{ $comentario ? '' : $plata($unitario) }}</td>
                @if ($columnas['descuento'])
                    <td class="der">{{ (float) ($l['descuento'] ?? 0) > 0 ? '− '.$plata($l['descuento']) : '' }}</td>
                @endif
                <td class="der">{{ $comentario ? '' : $plata($l['total']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
