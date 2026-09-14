{{--
    Cuándo y desde dónde se entrega. Una cotización es una oferta y no lo lleva;
    una nota de venta es un compromiso y sin esto no se puede despachar.
--}}
@php
    $filas = array_filter([
        'Fecha de entrega' => ! empty($documento['fecha_entrega']) ? $dia($documento['fecha_entrega']) : '',
        'Bodega' => trim((string) ($bodega ?? '')),
        'Centro de costo' => trim((string) ($centro_costo ?? '')),
    ]);
@endphp
@if ($filas)
    <div class="no-partir" style="margin-top: 14pt;">
        <h2>Despacho</h2>
        <table class="ficha" style="width:52%;">
            @foreach ($filas as $rotulo => $valor)
                <tr><td class="rot">{{ $rotulo }}</td><td>{{ $valor }}</td></tr>
            @endforeach
        </table>
    </div>
@endif
