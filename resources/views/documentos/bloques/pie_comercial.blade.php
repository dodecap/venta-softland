{{--
    El pie: dónde está la empresa y a qué teléfono llamar.

    Sale de la identidad, con su barra gris encima como en el original. La
    dirección es la **comercial** —la oficina—, que puede no ser la tributaria:
    Softland guarda la legal y el papel enseña la que corresponde.
--}}
<div class="barra" style="margin-top: 4mm;"></div>
<div class="pie-empresa">
    {{ implode(' ', array_filter([
        $identidad['direccion'],
        $identidad['comuna'] ? ','.$identidad['comuna'] : '',
        $identidad['fono'] ? 'Fono: '.$identidad['fono'] : '',
    ])) }}
</div>
