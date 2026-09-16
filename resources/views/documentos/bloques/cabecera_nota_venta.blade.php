{{--
    La cabecera de la nota de venta.

    Otra que la de la cotización, y a propósito: arriba el rótulo de la empresa,
    debajo los dos logos **al revés** —el propio a la izquierda— y una línea con
    los dos sitios web y el móvil. Es el membrete que el cliente reconoce.
--}}
<div class="rotulo-empresa">{{ mb_strtoupper($identidad['nombre_comercial'], 'UTF-8') }}</div>

<table class="logos" style="margin-top: 2mm;">
    <tr>
        <td style="width: 50%;">
            @if ($logo)
                <img class="logo-izq" src="{{ $logo }}" alt="{{ $identidad['nombre_comercial'] }}">
            @endif
        </td>
        <td style="width: 50%; text-align: right;">
            @if ($logo_secundario)
                <img class="logo-der" src="{{ $logo_secundario }}" alt="">
            @endif
        </td>
    </tr>
</table>

<div class="contacto-empresa" style="margin-top: 1.5mm;">
    {{ implode(' - ', array_filter([$identidad['web'], $identidad['email']])) }}
</div>
@if ($identidad['fono'])
    <div class="contacto-empresa">Móvil: {{ $identidad['fono'] }}</div>
@endif
