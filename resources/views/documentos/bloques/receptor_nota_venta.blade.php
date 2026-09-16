{{--
    El encabezado de la nota de venta: el número en su recuadro y el cliente.

    El número va en gris y grande porque es el dato por el que se busca el
    documento — en el teléfono, en el correo y en la conversación.

    «Glosa» es la observación del documento, y en el ciclo de distribuidor es la
    que dice bajo qué cliente final van los productos.
--}}
<table class="marco" style="margin-top: 3mm;">
    <tr>
        <td style="width: 16mm;" class="etiqueta">Ctz:</td>
        <td style="width: 24mm;">{{ ($documento['cotizacion'] ?? 0) ?: '' }}</td>
        <td style="text-align: center; font-size: 11pt; font-weight: bold;">
            {{ $tipo->titulo() }} #
            <span style="background: #d9d9d9; padding: 0 6mm;">{{ $documento['numero'] }}</span>
        </td>
        <td class="etiqueta-d">Fecha Em.:</td>
        <td style="width: 24mm; text-align: right;"><b>{{ $dia($documento['fecha'] ?? null) }}</b></td>
    </tr>
    <tr>
        <td colspan="2"></td>
        <td style="text-align: right;"><b>Estado:</b> {{ $tipo->estado($documento['estado'] ?? null) }}</td>
        <td class="etiqueta-d">Fecha Ent.:</td>
        <td style="text-align: right;"><b>{{ $dia($documento['fecha_entrega'] ?? null) }}</b></td>
    </tr>
</table>

<table class="marco" style="margin-top: 1.5mm;">
    <tr>
        <td class="etiqueta">Nombre:</td>
        <td class="subrayado">{{ $cliente['nombre'] ?? $documento['cliente'] ?? '' }}</td>
        <td class="etiqueta-d">R.U.T.:</td>
        <td style="width: 40mm;">{{ $rut($cliente['rut'] ?? '') }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Dirección</td>
        <td class="subrayado">{{ $cliente['direccion'] ?? '' }}</td>
        <td class="etiqueta-d"></td>
        <td></td>
    </tr>
    <tr>
        <td class="etiqueta">Ciudad:</td>
        <td>{{ $ciudad_cliente ?? '' }}</td>
        <td class="etiqueta-d">Comuna:</td>
        <td>{{ $comuna_cliente ?? '' }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Giro:</td>
        <td class="subrayado">{{ $giro_cliente ?? '' }}</td>
        <td class="etiqueta-d">Contacto:</td>
        <td>{{ $documento['contacto'] ?? '' }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Teléfono:</td>
        <td>{{ $contacto['fono'] ?? '' }}</td>
        <td class="etiqueta-d">Correo:</td>
        <td>{{ $contacto['email'] ?? '' }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Glosa:</td>
        <td>{{ $documento['observacion'] ?? '' }}</td>
        <td class="etiqueta-d">Cod. Venta:</td>
        <td>{{ $condicion ?? '' }}</td>
    </tr>
    <tr>
        <td colspan="2"></td>
        <td class="etiqueta-d">Vendedor:</td>
        <td>{{ $vendedor['nombre'] ?? '' }}</td>
    </tr>
</table>
