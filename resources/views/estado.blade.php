@extends('setup.layout', ['subtitulo' => 'Servidor de la aplicación móvil'])

@section('contenido')
    <div class="ok">
        El servidor está <strong>en línea y configurado</strong>. Base: <code>{{ $base }}</code>.
    </div>
    <p class="ayuda" style="margin-top:18px;">
        Este proyecto no tiene interfaz web: todo se opera desde la app Android.
        Esta dirección es la que hay que escribir en la pantalla <strong>Servidor</strong> del teléfono.
    </p>
    <p class="ayuda" style="margin-top:10px;">
        ¿Todavía no está instalada? El instalable y su código QR están en
        <a href="app"><strong>/app</strong></a>.
    </p>
@endsection
