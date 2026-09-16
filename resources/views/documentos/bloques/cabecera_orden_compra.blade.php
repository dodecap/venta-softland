{{--
    La cabecera de la orden de compra: quién la manda y a qué proveedor.

    Arriba a la izquierda, en un recuadro, el distribuidor — que aquí es la
    propia empresa: la orden va **desde** nosotros. A la derecha el título en
    azul y el número, que es el mismo de la nota de venta porque es el mismo
    trato mirado desde el otro lado.
--}}
<table>
    <tr>
        <td style="width: 45%; vertical-align: top;">
            <div style="border: .8pt solid #000; padding: 1.5mm 2mm; display: inline-block;">
                <div style="text-align: center; font-size: 8pt;">NOMBRE DISTRIBUIDOR</div>
                <div style="text-align: center; font-size: 8pt;">{{ $identidad['razon_social'] }}</div>
            </div>
            <table style="margin-top: 2mm;">
                <tr>
                    <td style="width: 22mm;">Teléfono</td>
                    <td>{{ $identidad['fono'] }}</td>
                </tr>
                <tr>
                    <td>Correo</td>
                    <td>{{ $identidad['email'] }}</td>
                </tr>
                <tr>
                    <td><b>Vendedor:</b></td>
                    <td>{{ $vendedor['nombre'] ?? '' }}</td>
                </tr>
            </table>
        </td>
        <td style="width: 55%; border: .8pt solid #000; text-align: center; padding: 3mm 2mm;">
            <div style="font-size: 15pt; font-weight: bold; color: #1f3864;">
                {{ mb_strtoupper($tipo->titulo(), 'UTF-8') }}
            </div>
            <div style="font-size: 13pt; font-weight: bold; color: #1f3864; margin-top: 3mm;">
                N° {{ $documento['numero'] }}
            </div>
        </td>
    </tr>
</table>

<table style="margin-top: 3mm;">
    <tr>
        <td style="width: 26mm; text-align: right; padding-right: 2mm;"><b>N° Cotiz.:</b></td>
        <td style="width: 30mm;"><b>{{ ($documento['cotizacion'] ?? 0) ?: '' }}</b></td>
        <td></td>
        <td style="width: 20mm; text-align: right; padding-right: 2mm;"><b>Fecha</b></td>
        <td style="width: 26mm; text-align: center;" class="subrayado"><b>{{ $dia($fecha_orden) }}</b></td>
    </tr>
    <tr>
        <td style="text-align: right; padding-right: 2mm;"><b>N° OC Cliente:</b></td>
        <td>{{ ($documento['oc'] ?? '') !== '0' ? ($documento['oc'] ?? '') : '' }}</td>
        <td colspan="3"></td>
    </tr>
</table>
