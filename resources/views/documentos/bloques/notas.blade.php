{{--
    La observación que escribió el vendedor. Va después de los totales porque es
    contexto, no condición: lo que obliga está arriba.
--}}
@if (! empty($documento['observacion']))
    <div class="no-partir" style="margin-top: 12pt;">
        <h2>Observaciones</h2>
        <div class="nota">{!! nl2br(e($documento['observacion'])) !!}</div>
    </div>
@endif
