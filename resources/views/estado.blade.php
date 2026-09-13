@extends('setup.layout', ['subtitulo' => 'Servidor de la aplicación móvil'])

@section('contenido')
    <div class="ok">
        El servidor está <strong>en línea y configurado</strong>. Base: <code>{{ $base }}</code>.
    </div>
    <p class="ayuda" style="margin-top:18px;">
        Este proyecto no tiene interfaz web: todo se opera desde la app Android.
        Esta dirección es la que hay que escribir en la pantalla <strong>Servidor</strong> del teléfono.
    </p>
@endsection
