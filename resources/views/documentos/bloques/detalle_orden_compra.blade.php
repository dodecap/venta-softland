{{--
    La rejilla de la orden de compra, con la cabecera azul.

    Lleva dos columnas que las otras no tienen, y las dos son del proveedor:

     - **Código**, que es el suyo, no el nuestro. Vive en `iw_tprod.DesProd2` —la
       segunda descripción del producto— y está vacío en los que no lo tienen,
       que es por lo que en el papel original sólo aparece en cuatro de diez
       líneas;
     - **Uf**, el precio tal como está tarifado. El de pesos va al lado, ya
       convertido.
--}}
<table class="rejilla oc">
    <tr>
        <th style="width: 18mm;">Codigo</th>
        <th>Descripción</th>
        <th style="width: 10mm;">Cant.</th>
        <th style="width: 13mm;">Uf</th>
        <th style="width: 19mm;">P. Unit $</th>
        <th style="width: 14mm;">% Descto.</th>
        <th style="width: 18mm;">$ Descto.</th>
        <th style="width: 20mm;">Total</th>
    </tr>
    @foreach ($filas as $l)
        <tr @class(['cierra' => $huecos <= 0 && $loop->last])>
            <td>{{ $codigos_proveedor[$l['producto']] ?? '' }}</td>
            <td>{{ $t($l['detalle'] ?? '') ?: ($nombres[$l['producto']] ?? $l['producto']) }}</td>
            <td class="num">{{ $cant($l['cantidad'] ?? 0) }}.</td>
            <td class="num">{{ ((float) ($l['equiv'] ?? 1)) > 1 ? $dec($l['precio'] ?? 0) : '' }}</td>
            <td class="num">{{ $plata(((float) ($l['precio'] ?? 0)) * ((float) ($l['equiv'] ?? 1))) }}</td>
            {{-- El porcentaje se calcula del propio descuento: el documento
                 guarda la plata, no el porcentaje, y en el papel del proveedor
                 lo que se lee es el 10 %. --}}
            <td class="centro">{{ $porcentaje($l) }}%</td>
            <td class="num">{{ ((float) ($l['descuento'] ?? 0)) ? $plata($l['descuento']) : '' }}</td>
            <td class="num">{{ $plata($l['total'] ?? 0) }}</td>
        </tr>
    @endforeach

    @for ($h = 0; $h < $huecos; $h++)
        <tr @class(['cierra' => $h === $huecos - 1])>
            <td></td><td></td><td></td><td></td><td></td>
            <td class="centro">%</td>
            <td></td><td></td>
        </tr>
    @endfor
</table>
