{{--
    La rejilla del detalle: 27 renglones, se usen o no.

    Los vacíos van dibujados a propósito, como en el original. En un documento
    tributario un renglón vacío con su raya dice «aquí no se añadió nada
    después», y ése es justamente el punto de imprimirlos.

    La descripción envuelve y hace crecer la fila: «COMISION: / Cliente: … /
    Distribuidor: … / N° Documento …» son cuatro renglones de una sola línea de
    detalle, y así es como salen en las facturas reales de INNOVAGES.
--}}
<table class="detalle">
    <tr>
        <th style="width: 7mm;">It.</th>
        <th style="width: 18mm;">Cantidad</th>
        <th style="width: 12mm;">UM</th>
        <th>Descripción</th>
        <th style="width: 20mm;">P.Unit</th>
        <th style="width: 20mm;">Descuento</th>
        <th style="width: 22mm;">Valor Total</th>
    </tr>
    @foreach ($renglones as $i => $l)
        <tr @class(['ultima' => $huecos <= 0 && $loop->last])>
            <td class="n">{{ $desde + $i + 1 }}</td>
            <td class="num">{{ $cant(abs((float) ($l['cantidad'] ?? 0))) }}</td>
            <td class="um">{{ $t($l['unidad'] ?? '') }}</td>
            <td>{!! nl2br(e($t($l['detalle'] ?? $l['glosa'] ?? ($nombres[$l['producto']] ?? $l['producto'] ?? '')))) !!}</td>
            <td class="num">{{ $plata($l['precio'] ?? 0) }}</td>
            <td class="num">{{ ((float) ($l['descuento'] ?? 0)) ? $plata($l['descuento']) : '' }}</td>
            <td class="num">{{ $plata(abs((float) ($l['total'] ?? 0))) }}</td>
        </tr>
    @endforeach

    {{-- Los renglones que sobran. Llevan el número, como en el original: la
         rejilla numerada de 1 a 27 es la que hace evidente que no falta uno. --}}
    @for ($h = 0; $h < $huecos; $h++)
        <tr @class(['ultima' => $h === $huecos - 1])>
            <td class="n">{{ $desde + count($renglones) + $h + 1 }}</td>
            <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
    @endfor
</table>
