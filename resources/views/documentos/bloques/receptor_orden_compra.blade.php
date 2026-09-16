{{--
    A quién se le pide: el proveedor.

    Sale de su ficha en Softland a partir del código que guarda la
    configuración. Sin configurar, la caja se dibuja vacía en vez de inventarse
    un destinatario — que en un documento que se manda fuera es lo último que
    uno querría.
--}}
<table style="margin-top: 3mm;">
    <tr>
        <td class="etiqueta" style="width: 24mm;">Señores:</td>
        <td class="subrayado">{{ $proveedor['nombre'] ?? '' }}</td>
        <td class="etiqueta-d" style="width: 24mm;">R.U.T.:</td>
        <td class="subrayado" style="width: 55mm;">{{ $proveedor['rut'] ?? '' }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Dirección:</td>
        <td class="subrayado">{{ $proveedor['direccion'] ?? '' }}</td>
        <td class="etiqueta-d">Comuna:</td>
        <td class="subrayado">{{ $proveedor['comuna'] ?? '' }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Ciudad:</td>
        <td class="subrayado">{{ $proveedor['ciudad'] ?? '' }}</td>
        <td class="etiqueta-d">Contacto:</td>
        <td class="subrayado">{{ $proveedor['contacto'] ?? '' }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Giro:</td>
        <td class="subrayado">{{ $proveedor['giro'] ?? '' }}</td>
        <td class="etiqueta-d">Correo:</td>
        <td class="subrayado">{{ $proveedor['correo'] ?? '' }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Teléfono:</td>
        <td class="subrayado">{{ $proveedor['fono'] ?? '' }}</td>
        <td colspan="2"></td>
    </tr>
</table>
