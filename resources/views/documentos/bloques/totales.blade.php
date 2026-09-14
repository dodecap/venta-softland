{{--
    El cierre del documento: condiciones a la izquierda, plata a la derecha.

    Las condiciones se dibujan **desde aquí** y no como bloque suelto porque van
    a la misma altura que los totales — es el reparto del papel que usa
    cualquier documento comercial, y dompdf no tiene con qué poner dos bloques
    consecutivos uno al lado del otro sin una tabla que los contenga. El bloque
    `condiciones` existe igual, para los tipos que no llevan totales (la guía de
    despacho), y se salta solo cuando ya se dibujaron aquí.

    Todo el conjunto lleva `no-partir`: un total huérfano en una hoja vacía se
    ve peor que una hoja de más.
--}}
@php
    $conCondiciones = in_array('condiciones', $bloques, true) && ! empty($condiciones);
@endphp

<table class="cierre no-partir" style="margin-top: 10pt;">
    <tr>
        <td style="width:56%; padding-right:18pt;">
            @if ($conCondiciones)
                <h2>Condiciones comerciales</h2>
                <div class="condiciones">
                    @foreach ($condiciones as $linea)
                        <div>· {{ $linea }}</div>
                    @endforeach
                </div>
            @endif
        </td>
        <td style="width:44%;">
            <table class="totales">
                @if ((float) ($documento['descuento'] ?? 0) > 0)
                    <tr>
                        <td class="rot">Descuento</td>
                        <td class="der">− {{ $plata($documento['descuento']) }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="rot">Neto</td>
                    <td class="der">{{ $plata($documento['neto'] ?? 0) }}</td>
                </tr>
                {{-- El exento se muestra siempre, aunque sea cero: en un
                     documento comercial chileno su ausencia se lee como un
                     olvido, no como un cero. --}}
                <tr>
                    <td class="rot">Exento</td>
                    <td class="der">{{ $plata($documento['exento'] ?? 0) }}</td>
                </tr>
                {{-- Una fila por impuesto. Hoy siempre es el IVA; el día que se
                     calcule el ILA entra aquí sin tocar la plantilla. --}}
                @foreach ($impuestos as $imp)
                    <tr>
                        <td class="rot">{{ $imp['nombre'] }}</td>
                        <td class="der">{{ $plata($imp['monto']) }}</td>
                    </tr>
                @endforeach
                <tr class="final">
                    <td>Total</td>
                    <td class="der">{{ $plata($documento['total'] ?? 0) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
