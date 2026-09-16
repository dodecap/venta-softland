{{--
    El acuse de recibo de la ley 19.983.

    Es texto de ley y va tal cual: la firma del receptor en este recuadro es lo
    que convierte la factura en un documento cedible — el que se puede vender a
    un factoring. Por eso el pie de la hoja legal no lleva paginado: este
    recuadro ocupa ese sitio y no se mueve.
--}}
<table class="acuse">
    <tr>
        <td style="width: 50%;">Nombre: <span class="campo">&nbsp;</span></td>
        <td style="width: 25%;">RUT: <span class="campo">&nbsp;</span></td>
        <td style="width: 25%;">Fecha: <span class="campo">&nbsp;</span></td>
    </tr>
    <tr>
        <td colspan="2" class="legal">
            El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la letra b)
            del artículo 4°, y la letra c) del artículo 5° de la ley 19.983, acredita que la entrega
            de mercadería(s) o servicio(s) prestado(s) ha(n) sido recibido(s).
        </td>
        <td style="text-align: center; vertical-align: bottom;">Firma.</td>
    </tr>
</table>
<div class="creado">
    {{ mb_strtoupper($tipo->titulo(), 'UTF-8') }} CREADA POR
    {{ mb_strtoupper($identidad['nombre_comercial'], 'UTF-8') }}@if ($identidad['web']) - {{ $identidad['web'] }}@endif
</div>
