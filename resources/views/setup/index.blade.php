@extends('setup.layout', ['subtitulo' => 'Instalación del servidor'])

@section('contenido')
    {{-- Lo que el servidor necesita tener, antes que nada. Sólo se enseña la
         lista entera cuando algo falla: si está todo bien, quien instala no
         necesita leer ocho líneas verdes para llegar al formulario. --}}
    @unless ($listo)
        <div class="errores">
            <strong>Falta algo en este servidor.</strong>
            Arregla lo marcado en rojo y recarga esta página.
        </div>

        <ul class="requisitos">
            @foreach ($requisitos as $r)
                <li class="{{ $r['ok'] ? 'si' : 'no' }}">
                    <span class="marca">{{ $r['ok'] ? '✓' : '✕' }}</span>
                    <div>
                        <strong>{{ $r['que'] }}</strong>
                        <p class="ayuda">{{ $r['detalle'] }}</p>
                        @unless ($r['ok'])
                            <p class="ayuda arreglo">{{ $r['arreglo'] }}</p>
                        @endunless
                    </div>
                </li>
            @endforeach
        </ul>
    @endunless

    @if ($yaConfigurado && $listo)
        <div class="aviso">
            <strong>Este servidor ya está instalado</strong>
            @if ($baseActual) sobre la base <code>{{ $baseActual }}</code>@endif.
            Si sigues, la conexión se reemplaza por la que escribas aquí y los vendedores
            dejarán de ver sus documentos hasta que vuelvas a dejarla como estaba.
            Para repartir la app no hace falta esto: está en <a href="app">/app</a>.
        </div>
    @endif

    @if ($errors->any())
        <div class="errores">
            <ul>
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Se manda a sí misma: `action=""` es la propia dirección que el navegador
         tiene en la barra, y ésa es siempre la correcta. Escribirla con url()
         ponía la carpeta interna del Alias, que detrás de un proxy da 404. --}}
    @if ($listo)
    <form method="POST" action="" autocomplete="off">
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
                           value="{{ old('database') }}" placeholder="Nombre de la empresa" required>
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

        <button type="submit">{{ $yaConfigurado ? 'Reemplazar la conexión' : 'Instalar' }}</button>
    </form>
    @endif
@endsection
