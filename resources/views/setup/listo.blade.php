@extends('setup.layout', ['subtitulo' => 'Instalación completada'])

@section('contenido')
    <div class="ok">
        <strong>Listo.</strong> El esquema <code>ventas</code> quedó creado en la base
        <code>{{ $base }}</code> y el usuario <code>softland</code> registrado como administrador.
    </div>

    <h2 style="font-size:15px;color:#1d1060;margin:24px 0 8px;">Y ahora, desde el celular</h2>
    <ol class="pasos">
        <li>Instala el APK en el teléfono.</li>
        <li>Al abrirlo, en <strong>Servidor</strong>, escribe la dirección de esta instalación.</li>
        <li>Entra con el usuario <code>softland</code> y su contraseña.</li>
        <li>Crea los vendedores en <strong>Usuarios</strong> y enlázalos con su código de vendedor
            de Softland.</li>
        <li>Configura el correo en <strong>Configuración → Correo</strong> y manda una prueba.</li>
    </ol>

    <p class="ayuda" style="margin-top:20px;">
        Esta página ya no volverá a mostrarse. Para cambiar la conexión más adelante se hace
        desde la app, con rol admin y la contraseña de Softland.
    </p>
@endsection
