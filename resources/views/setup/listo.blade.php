@extends('setup.layout', ['subtitulo' => 'Instalación completada'])

@section('contenido')
    <div class="ok">
        <strong>Listo.</strong> El esquema <code>ventas</code> quedó creado en la base
        <code>{{ $base }}</code> y el usuario <code>softland</code> registrado como administrador.
    </div>

    {{-- Lo que falta sin ser imprescindible no impide instalar, pero callarlo
         sería dejar que se descubriera el día que alguien intente usarlo. --}}
    @if ($limita)
        <div class="aviso">
            <strong>Con esta base no va a poder hacerse todo.</strong>
            A la base <code>{{ $base }}</code> le faltan tablas o columnas, así que esto
            queda fuera:
            <ul style="margin:8px 0 0 18px;">
                @foreach ($limita as $que)
                    <li>{{ $que }}</li>
                @endforeach
            </ul>
            <p class="ayuda" style="margin-top:8px;">
                El detalle, desde el servidor:
                <code>php artisan ventas:compatibilidad</code>
            </p>
        </div>
    @endif

    {{-- Lo siguiente que hay que hacer es instalar la app, así que el enlace va
         aquí y grande. Antes esta página decía «instala el APK» sin decir de
         dónde, y quien instalaba tenía que adivinar que existía /app.

         Y antes que eso, traerlo: el APK no viaja en el repositorio, viaja en
         la publicación. Un servidor recién instalado no lo tiene, y quien
         instala no tiene por qué enterarse encontrándose /app vacío. --}}
    <div class="aviso">
        <strong>Antes del código QR</strong>, trae el instalable a este servidor.
        Desde una consola, en la carpeta del proyecto:
        <p style="margin:8px 0 0;"><code>php artisan ventas:actualizar --apk</code></p>
    </div>

    <a class="boton-enlace" href="../app">Ver el código QR para instalar la app</a>

    <h2 style="font-size:15px;color:#1d1060;margin:26px 0 8px;">Y ahora, desde el celular</h2>
    <ol class="pasos">
        <li>Escanea el código QR de <code>/app</code> e instala el APK.</li>
        <li>Al abrirlo, en <strong>Servidor</strong>, escribe la dirección de esta instalación.</li>
        <li>Entra con el usuario <code>softland</code> y su contraseña.</li>
        <li>Crea los vendedores en <strong>Cuenta → Usuarios</strong> y enlázalos con su código
            de vendedor de Softland.</li>
        <li>Pon los datos de la empresa en <strong>Cuenta → Identidad</strong>: lo que quede
            vacío lo hereda de Softland.</li>
        <li>Configura el correo en <strong>Cuenta → Configuración</strong> y manda una prueba.</li>
    </ol>

    <p class="ayuda" style="margin-top:20px;">
        La dirección que hay que escribir en el teléfono es la de esta misma página, sin
        <code>/setup/listo</code> al final.
    </p>
@endsection
