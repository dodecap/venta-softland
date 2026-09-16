{{--
    El detalle de la cotización, con el valor en UF.

    Así lo cotiza la empresa: el producto está tarifado en UF y el subtotal sale
    en pesos, con la UF del día impresa abajo en las condiciones. La columna de
    UF va vacía cuando el producto se cotizó directamente en pesos — que también
    pasa— en vez de escribir un número que no significa nada.

    El precio de la línea está en la moneda **del producto** y el total en la
    del documento; `equiv` es el factor entre las dos. Por eso la columna de UF
    se llena cuando `equiv` es distinto de 1, y no antes.
--}}
<table class="rejilla">
    <tr>
        <th>Detalle</th>
        <th style="width: 12mm;">Cant</th>
        <th style="width: 22mm;">Valor UF<br>Neto/Excento</th>
        <th style="width: 22mm;">% Descuento</th>
        <th style="width: 22mm;">$ Descuento</th>
        <th style="width: 26mm;">Sub Total $<br>Neto/Exento</th>
    </tr>
    @foreach ($filas as $l)
        <tr @class(['cierra' => $huecos <= 0 && $loop->last])>
            <td>{{ $t($l['detalle'] ?? '') ?: ($nombres[$l['producto']] ?? $l['producto']) }}</td>
            <td class="num">{{ $cant($l['cantidad'] ?? 0) }}.</td>
            {{-- Vacía cuando la línea se cotizó directamente en pesos: la
                 equivalencia en 1 quiere decir que no hay nada que convertir, y
                 escribir ahí el precio en pesos sería llamarlo UF. --}}
            <td class="num">{{ ((float) ($l['equiv'] ?? 1)) > 1 ? $dec($l['precio'] ?? 0) : '' }}</td>
            <td class="centro">{{ $porcentaje($l) }} %</td>
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
