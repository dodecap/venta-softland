{{--
    La firma. Los datos salen del vendedor del documento, nunca escritos a mano:
    el nombre y el correo de `softland.cwtvend`, el cargo y el fono de
    `ventas.usuario`, que es donde la app guarda lo suyo.

    El día que haya firma digitalizada, va aquí y en ningún otro sitio.
--}}
@if (! empty($vendedor['nombre']))
    <div class="no-partir firma" style="margin-top: 12pt;">
        <div class="tenue">Atentamente,</div>
        <div class="nombre" style="margin-top: 8pt;">{{ $vendedor['nombre'] }}</div>
        @if (! empty($vendedor['cargo']))
            <div class="tenue">{{ $vendedor['cargo'] }}</div>
        @endif
        @php
            $via = array_filter([$vendedor['fono'] ?? '', $vendedor['email'] ?? '']);
        @endphp
        @if ($via)
            <div class="tenue">{{ implode(' · ', $via) }}</div>
        @endif
    </div>
@endif
