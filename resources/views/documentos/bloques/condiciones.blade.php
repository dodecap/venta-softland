{{--
    Las condiciones sueltas, para los tipos que no llevan bloque de totales
    — hoy sólo la guía de despacho. Cuando el documento tiene totales, las
    dibuja `totales.blade.php` a su izquierda, y este bloque se calla: si no,
    saldrían dos veces.
--}}
@if (! in_array('totales', $bloques, true) && ! empty($condiciones))
    <div class="no-partir" style="margin-top: 12pt;">
        <h2>Condiciones comerciales</h2>
        <div class="condiciones">
            @foreach ($condiciones as $linea)
                <div>· {{ $linea }}</div>
            @endforeach
        </div>
    </div>
@endif
