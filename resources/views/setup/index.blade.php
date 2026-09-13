@extends('setup.layout', ['subtitulo' => 'Instalación del servidor'])

@section('contenido')
    @if ($errors->any())
        <div class="errores">
            <ul>
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- url() respeta la subcarpeta del Alias: la app vive en /venta-softland, no en la raíz. --}}
    <form method="POST" action="{{ url('/setup') }}" autocomplete="off">
        @csrf

        <fieldset>
            <legend>Base de datos Softland</legend>

            <label for="host">Servidor SQL</label>
            <input type="text" id="host" name="host"
                   value="{{ old('host', 'localhost\\MSSQLSERVER2022') }}" required>
            <p class="ayuda">
                Si la instancia es nombrada va como <code>host\INSTANCIA</code> y el puerto se deja en blanco.
            </p>

            <div class="fila">
                <div>
                    <label for="database">Base de datos (empresa)</label>
                    <input type="text" id="database" name="database"
                           value="{{ old('database', 'INNOVAGES') }}" required>
                </div>
                <div class="angosto">
                    <label for="port">Puerto</label>
                    <input type="text" id="port" name="port" value="{{ old('port') }}" placeholder="—">
                </div>
            </div>
            <p class="ayuda">
                Cada empresa de Softland es una base distinta. La app crea su propio esquema
                <code>ventas</code> dentro de ella y nunca escribe en el esquema <code>softland</code>
                sin pasar por el flujo de la aplicación.
            </p>

            <div class="fila">
                <div>
                    <label for="sa_user">Usuario SQL</label>
                    <input type="text" id="sa_user" name="sa_user" value="{{ old('sa_user', 'sa') }}" required>
                </div>
                <div>
                    <label for="sa_password">Contraseña SQL</label>
                    <input type="password" id="sa_password" name="sa_password" required>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Autorización de Softland</legend>

            <div class="fila">
                <div>
                    <label for="softland_user">Usuario Softland</label>
                    <input type="text" id="softland_user" name="softland_user"
                           value="{{ old('softland_user', 'softland') }}" required>
                </div>
                <div>
                    <label for="softland_password">Contraseña</label>
                    <input type="password" id="softland_password" name="softland_password" required>
                </div>
            </div>
            <p class="ayuda">
                Se valida contra <code>softland.wisusuarios</code>. Solo el administrador
                <code>softland</code> puede instalar: así nadie levanta el sistema sobre una base
                ajena sin autorización.
            </p>
        </fieldset>

        <button type="submit">Instalar</button>
    </form>
@endsection
