@extends('setup.layout', ['subtitulo' => 'Instalación completada'])

@section('contenido')
    <div class="ok">
        <strong>Listo.</strong> El esquema <code>ventas</code> quedó creado en la base
        <code>{{ $base }}</code> y el usuario <code>softland</code> registrado como administrador.
    </div>

    {{-- Lo siguiente que hay que hacer es instalar la app, así que el enlace va
         aquí y grande. Antes esta página decía «instala el APK» sin decir de
         dónde, y quien instalaba tenía que adivinar que existía /app. --}}
    <a class="boton-enlace" href="../app">Ver el código QR para instalar la app</a>

    <h2 style="font-size:15px;color:#1d1060;margin:26px 0 8px;">Y ahora, desde el celular</h2>
    <ol class="pasos">
        <li>Escanea el código QR de la página anterior e instala el APK.</li>
        <li>Al abrirlo, en <strong>Servidor</strong>, escribe la dirección de esta instalación.</li>
        <li>Entra con el usuario <code>softland</code> y su contraseña.</li>
        <li>Crea los vendedores en <strong>Usuarios</strong> y enlázalos con su código de vendedor
            de Softland.</li>
        <li>Pon los datos de la empresa en <strong>Configuración → Identidad</strong>: lo que quede
            vacío lo hereda de Softland.</li>
        <li>Configura el correo en <strong>Configuración → Correo</strong> y manda una prueba.</li>
    </ol>

    <p class="ayuda" style="margin-top:20px;">
        La dirección que hay que escribir en el teléfono es la de esta misma página, sin
        <code>/setup/listo</code> al final.
    </p>
@endsection
