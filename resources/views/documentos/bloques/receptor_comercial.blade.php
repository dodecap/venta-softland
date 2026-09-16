{{--
    A quién va dirigida la cotización.

    Dos columnas dentro de un marco, como el original: a la izquierda quién es y
    dónde está, a la derecha el RUT, la comuna, quién lo atiende y cómo paga.

    «Atención» es el contacto de la empresa cliente —la persona con la que se
    habla— y «Atendido Por» es el vendedor. Se parecen y son cosas distintas.
--}}
<table class="marco">
    <tr>
        <td class="etiqueta">Señores:</td>
        <td>{{ $cliente['nombre'] ?? $documento['cliente'] ?? '' }}</td>
        <td class="etiqueta-d">Rut:</td>
        <td style="width: 32mm;">{{ $rut($cliente['rut'] ?? '') }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Giro:</td>
        <td>{{ $giro_cliente ?? '' }}</td>
        <td class="etiqueta-d"></td>
        <td></td>
    </tr>
    <tr>
        <td class="etiqueta">Direccion:</td>
        <td>{{ $cliente['direccion'] ?? '' }}</td>
        <td class="etiqueta-d">Comuna</td>
        <td>{{ $comuna_cliente ?? '' }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Atencion:</td>
        <td>{{ $documento['contacto'] ?? ($contacto['nombre'] ?? '') }}</td>
        <td class="etiqueta-d">Atendido Por</td>
        <td>{{ $vendedor['nombre'] ?? '' }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Telefono:</td>
        <td>{{ $contacto['fono'] ?? ($cliente['fono'] ?? '') }}</td>
        <td class="etiqueta-d">Forma de Pago</td>
        <td>{{ mb_strtoupper($condicion ?? '', 'UTF-8') }}</td>
    </tr>
</table>
