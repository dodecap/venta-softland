{{--
    A quién se le factura.

    Dos columnas, como el original: a la izquierda quién es y dónde está; a la
    derecha los datos del trato — fecha, condición, vendedor y de qué nota de
    venta sale.

    Ojo con «Descripción»: en el papel de Softland es la glosa del documento, no
    la del producto. En las facturas de comisión de INNOVAGES es lo único que
    dice de qué va la factura.
--}}
<div class="marco">
    <table class="datos">
        <tr>
            <td class="etiqueta">Señor(es):</td>
            <td>{{ $cliente['nombre'] ?? $documento['cliente'] ?? '' }}</td>
            <td class="etiqueta-d">Fecha:</td>
            <td style="width: 26mm;">{{ $dia($documento['fecha'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="etiqueta">Giro:</td>
            <td>{{ $giro_cliente ?? '' }}</td>
            <td class="etiqueta-d">R.U.T.:</td>
            <td>{{ $rut($cliente['rut'] ?? '') }}</td>
        </tr>
        <tr>
            <td class="etiqueta">Dirección:</td>
            <td>{{ $cliente['direccion'] ?? '' }}</td>
            <td class="etiqueta-d">Ciudad:</td>
            <td>{{ $ciudad_cliente ?? '' }}</td>
        </tr>
        <tr>
            <td class="etiqueta">Comuna:</td>
            <td>{{ $comuna_cliente ?? '' }}</td>
            <td class="etiqueta-d">Cond. Venta:</td>
            <td>{{ mb_strtoupper($condicion ?? '', 'UTF-8') }}</td>
        </tr>
        <tr>
            <td class="etiqueta">Teléfono:</td>
            <td>{{ $cliente['fono'] ?? '' }}</td>
            <td class="etiqueta-d">Vendedor:</td>
            <td>{{ $vendedor['nombre'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="etiqueta">Descripción:</td>
            <td>{{ $documento['glosa'] ?? '' }}</td>
            <td class="etiqueta-d">N. Venta:</td>
            <td>{{ ($documento['nota_venta'] ?? 0) ?: '' }}</td>
        </tr>
        <tr>
            <td colspan="2"></td>
            <td class="etiqueta-d">C C:</td>
            <td>{{ $documento['centro_costo'] ?? '' }}</td>
        </tr>
    </table>
</div>
