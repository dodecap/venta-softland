{{--
    A quién hay que facturarle lo que se está pidiendo.

    Es el corazón del ciclo de distribuidor: la orden va al proveedor, pero la
    factura que salga de ella tiene que ir al **cliente final**. Por eso esta
    caja lleva su ficha entera —RUT, dirección, giro y contacto— y arriba,
    encima de todo, «PRODUCTOS BAJO», que es la glosa con la que se identifica
    esa venta en los dos lados.

    A la derecha, la UF con la que se calculó y su fecha: sin ella los números
    de la columna «Uf» no se pueden comprobar.
--}}
@if ($documento['observacion'] ?? null)
    <div style="margin-top: 3mm; font-weight: bold; color: #c00000;">
        PRODUCTOS BAJO: {{ mb_strtoupper($documento['observacion'], 'UTF-8') }}
    </div>
@endif

<table class="cierre">
    <tr>
        <td style="width: 62%; padding-right: 4mm;">
            <div style="font-weight: bold; margin-bottom: 1mm;">FACTURAR A:</div>
            <table>
                <tr>
                    <td class="etiqueta-d" style="width: 24mm;">Nombre:</td>
                    <td>{{ $cliente['nombre'] ?? $documento['cliente'] ?? '' }}</td>
                </tr>
                <tr>
                    <td class="etiqueta-d">R.U.T.:</td>
                    <td>{{ $rut($cliente['rut'] ?? '') }}</td>
                </tr>
                <tr>
                    <td class="etiqueta-d">Dirección:</td>
                    <td>{{ $cliente['direccion'] ?? '' }}</td>
                </tr>
                <tr>
                    <td class="etiqueta-d">Comuna:</td>
                    <td>{{ $comuna_cliente ?? '' }}</td>
                </tr>
                <tr>
                    <td class="etiqueta-d">Ciudad:</td>
                    <td>{{ $ciudad_cliente ?? '' }}</td>
                </tr>
                <tr>
                    <td class="etiqueta-d">Giro:</td>
                    <td>{{ $giro_cliente ?? '' }}</td>
                </tr>
                <tr>
                    <td class="etiqueta-d">Teléfono:</td>
                    <td>{{ $contacto['fono'] ?? ($cliente['fono'] ?? '') }}</td>
                </tr>
                <tr>
                    <td class="etiqueta-d">Contacto:</td>
                    <td>{{ $documento['contacto'] ?? '' }}</td>
                </tr>
                <tr>
                    <td class="etiqueta-d">Email:</td>
                    <td>{{ $contacto['email'] ?? ($cliente['email'] ?? '') }}</td>
                </tr>
            </table>
        </td>
        <td style="width: 38%;">
            @if ($uf_documento)
                <table class="marco" style="margin-bottom: 2mm;">
                    <tr>
                        <td class="etiqueta">Valor Uf:</td>
                        <td style="text-align: right;">{{ $dec($uf_documento) }}</td>
                    </tr>
                    <tr>
                        <td class="etiqueta">Al</td>
                        <td style="text-align: right;">{{ $dia($fecha_orden) }}</td>
                    </tr>
                </table>
            @endif

            <table class="totales">
                <tr>
                    <td class="rotulo">Neto Exento</td>
                    <td class="valor">{{ $plata($documento['exento'] ?? 0) }}</td>
                </tr>
                <tr>
                    <td class="rotulo">NetoAfeto</td>
                    <td class="valor">{{ $plata($documento['neto'] ?? 0) }}</td>
                </tr>
                <tr>
                    <td class="rotulo">Descto.</td>
                    <td class="valor">{{ $plata($documento['descuento'] ?? 0) }}</td>
                </tr>
                <tr>
                    <td class="rotulo">SubTotal</td>
                    <td class="valor">{{ $plata(($documento['neto'] ?? 0) + ($documento['exento'] ?? 0)) }}</td>
                </tr>
                @foreach ($impuestos as $imp)
                    <tr>
                        <td class="rotulo">{{ str_starts_with($imp['nombre'], 'IVA') ? 'Iva' : $imp['nombre'] }}</td>
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
