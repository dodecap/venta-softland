{{--
    De qué documentos viene éste.

    En una factura es la nota de venta; en una nota de crédito, la factura que
    anula — y ahí no es adorno, es lo que el SII lee para saber qué se está
    anulando. Cuando no hay ninguna referencia el renglón no se dibuja: una
    franja vacía que dice «Documentos de referencia» y nada más sólo confunde.
--}}
@if (! empty($referencias))
    <div class="referencias">
        <span class="rotulo">Documentos de referencia:</span>
        @foreach ($referencias as $r)
            {{ $r }}@if (! $loop->last);@endif
        @endforeach
    </div>
@endif
