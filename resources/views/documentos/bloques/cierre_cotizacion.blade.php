{{--
    El cierre de la cotización: lo que se le dice al cliente y quién firma.

    Los dos párrafos y el cargo salen de la identidad y de la ficha del
    vendedor. Que la firma lleve nombre y cargo no es formalidad: es a quién
    llamar.
--}}
@if ($nota_pago)
    <p class="parrafo">{{ $nota_pago }}</p>
@endif

@if ($despedida)
    <p class="parrafo">{{ $despedida }}</p>
@endif

<div class="firma">
    <div style="text-align: left; padding-left: 28mm;">Atentamente,</div>
    <div class="nombre">{{ $vendedor['nombre'] ?? '' }}</div>
    @if (! empty($vendedor['cargo']))
        <div>{{ $vendedor['cargo'] }}</div>
    @endif
</div>
