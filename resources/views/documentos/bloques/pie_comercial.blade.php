{{--
    El pie: dónde está la empresa y a qué teléfono llamar.

    Sale de la identidad, con su barra gris encima como en el original.

    La dirección es la **comercial** —la oficina a la que va el cliente— y tiene
    campo propio: la tributaria se imprime en la factura, donde tiene que decir
    lo mismo que el XML que recibió el SII. Las dos son ciertas y las dos salen,
    en papeles distintos.
--}}
<div class="barra" style="margin-top: 4mm;"></div>
<div class="pie-empresa">
@php
    $donde = array_filter([
        $identidad['direccion_comercial'] ?: $identidad['direccion'],
        $identidad['comuna_comercial'] ?: $identidad['comuna'],
    ]);
@endphp
    {{ implode(', ', $donde) }}@if ($identidad['fono']) Fono: {{ $identidad['fono'] }}@endif
</div>
