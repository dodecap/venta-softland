@extends('setup.layout', ['subtitulo' => 'Instalar la app en un teléfono'])

@section('contenido')
    @if ($apk)
        <style>
            .qr {
                display: block; width: 100%; max-width: 250px; margin: 0 auto 20px;
                padding: 14px; background: #fff; border: 1px solid var(--gris-borde);
                border-radius: 10px; line-height: 0;
            }
            .qr img { width: 100%; height: auto; display: block; }
            .bajar {
                display: block; width: 100%; padding: 15px; font-size: 16px; font-weight: 700;
                color: #fff; background: var(--indigo); border-bottom: 4px solid var(--cian);
                border-radius: 6px; text-decoration: none; text-align: center;
            }
            .bajar:hover { background: var(--indigo-claro); }
            .version { text-align: center; font-size: 13px; color: var(--gris-texto); margin: 0 0 18px; }
            .version strong { color: var(--indigo); font-size: 16px; }
        </style>

        <p class="version">
            Versión <strong>{{ $apk['version'] }}</strong> ·
            {{ number_format($apk['bytes'] / 1048576, 1, ',', '.') }} MB
        </p>

        {{-- El código y el botón llevan al mismo sitio: el código para el
             teléfono que está mirando la pantalla de otro, el botón para el que
             ya tiene esta página abierta. --}}
        <a class="qr" id="enlace-qr" href="app/apk">
            <img id="qr" src="app/qr.svg" alt="Código QR con la dirección de descarga" width="222" height="222">
        </a>

        <a class="bajar" id="bajar" href="app/apk">Descargar el instalable</a>

        <p class="ayuda" style="margin-top:18px;">
            Esta dirección entrega <strong>siempre la última versión publicada</strong> y no
            cambia al subir la siguiente: se puede guardar en favoritos o imprimir el código.
        </p>
        <ol class="pasos" style="margin-top:12px;">
            <li>Apunta la cámara al código, o toca el botón desde el propio teléfono.</li>
            <li>Android pedirá permiso para instalar desde esta fuente: hay que dárselo.</li>
            <li>Al abrirla por primera vez pide la dirección del servidor:
                <code id="direccion">{{ url('/') }}</code>.</li>
        </ol>

        <script>
            /* Detrás del proxy el servidor no sabe por qué dirección le hablaron
               —ni el esquema ni la carpeta—, así que un enlace escrito por él
               llevaría dentro una que no abre. El navegador sí lo sabe. Las
               rutas relativas de arriba ya salen bien mientras la página se pida
               sin barra final, que es como está declarada; esto lo deja bien
               también con barra, y sobre todo le dice al servidor qué dirección
               tiene que meter dentro del código. */
            (function () {
                var base = location.href.replace(/[?#].*$/, '').replace(/\/+$/, '');
                var apk = base + '/apk';
                document.getElementById('bajar').href = apk;
                document.getElementById('enlace-qr').href = apk;
                document.getElementById('qr').src = base + '/qr.svg?u=' + encodeURIComponent(apk);
                document.getElementById('direccion').textContent =
                    base.replace(/\/app$/, '') || location.origin;
            })();
        </script>
    @else
        <div class="errores">
            Todavía no se ha publicado ningún instalable en este servidor. Se sube
            desde el equipo de desarrollo con <code>bin/publicar-apk.sh</code>.
        </div>
    @endif
@endsection
