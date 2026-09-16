{{--
    El pie de la rejilla: condiciones a la izquierda, totales a la derecha.

    Las condiciones comerciales salen de la identidad, una por línea, y el valor
    de la UF se añade al final cuando el documento lleva algo tarifado en UF:
    sin él, los números en UF de la rejilla no se pueden comprobar.
--}}
<table class="cierre">
    <tr>
        <td style="width: 62%; padding-right: 4mm;">
            <div class="condiciones">
                @if ($condiciones_empresa || $uf_documento)
                    <div class="titulo">CONDICIONES COMERCIALES</div>
                    <ul>
                        @foreach ($condiciones_empresa as $c)
                            <li>{{ $c }}</li>
                        @endforeach
                        @if ($uf_documento)
                            <li><b>Valor UF Cotizado: $ {{ $dec($uf_documento) }}</b></li>
                        @endif
                    </ul>
                @endif
            </div>
        </td>
        <td style="width: 38%;">
            <table class="totales">
                <tr>
                    <td class="rotulo">T. Descuento</td>
                    <td class="valor">{{ ((float) ($documento['descuento'] ?? 0)) ? $plata($documento['descuento']) : '-' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">Total Neto</td>
                    <td class="valor">{{ $plata($documento['neto'] ?? 0) }}</td>
                </tr>
                <tr>
                    <td class="rotulo">Total Exento</td>
                    <td class="valor">{{ ((float) ($documento['exento'] ?? 0)) ? $plata($documento['exento']) : '-' }}</td>
                </tr>
                {{-- Una fila por impuesto: hoy siempre el IVA, y el día que se
                     calcule otro entra aquí sin tocar la plantilla. --}}
                @foreach ($impuestos as $imp)
                    <tr>
                        {{-- «I.V.A» a secas, como en el papel de la empresa: el
                             porcentaje ya está en la ley y repetirlo en la caja
                             de totales sólo la ensancha. --}}
                        <td class="rotulo">{{ str_starts_with($imp['nombre'], 'IVA') ? 'I.V.A' : $imp['nombre'] }}</td>
                        <td class="valor">{{ $plata($imp['monto']) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td class="rotulo"><b>Total</b></td>
                    <td class="valor"><b>{{ $plata($documento['total'] ?? 0) }}</b></td>
                </tr>
            </table>
        </td>
    </tr>
</table>
