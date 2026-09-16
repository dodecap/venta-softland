{{--
    La rejilla de la nota de venta: en pesos, con el descuento en plata.

    A diferencia de la cotización aquí no hay columna de UF — el documento ya
    está cerrado y lo que importa es lo que se va a cobrar—, y el precio va
    convertido a la moneda del documento.
--}}
<table class="rejilla">
    <tr>
        <th style="width: 10mm;">Cant.</th>
        <th style="width: 12mm;">UM</th>
        <th>Descripción</th>
        <th style="width: 26mm;">P.Unit</th>
        <th style="width: 24mm;">Descto.</th>
        <th style="width: 26mm;">Total</th>
    </tr>
    @foreach ($filas as $l)
        <tr @class(['cierra' => $huecos <= 0 && $loop->last])>
            <td>{{ $cant($l['cantidad'] ?? 0) }}.</td>
            <td class="centro">{{ $t($l['unidad'] ?? '') }}</td>
            <td>{{ $t($l['detalle'] ?? '') ?: ($nombres[$l['producto']] ?? $l['producto']) }}</td>
            <td class="num">{{ $plata(((float) ($l['precio'] ?? 0)) * ((float) ($l['equiv'] ?? 1))) }}</td>
            <td class="num">{{ ((float) ($l['descuento'] ?? 0)) ? $plata($l['descuento']) : '' }}</td>
            <td class="num">{{ $plata($l['total'] ?? 0) }}</td>
        </tr>
    @endforeach

    @for ($h = 0; $h < $huecos; $h++)
        <tr @class(['cierra' => $h === $huecos - 1])>
            <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
    @endfor
</table>
