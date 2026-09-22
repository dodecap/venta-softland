<?php

namespace App\Http\Controllers;

use App\Services\Notificaciones\Notificador;
use App\Services\Softland\Compatibilidad;
use App\Support\Requisitos;
use App\Support\SoftlandCipher;
use App\Support\SoftlandConfig;
use App\Support\SoftlandConnection;
use App\Support\Rutas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Única página HTML del proyecto: la instalación.
 *
 * Se usa una sola vez, desde un navegador con acceso al servidor, porque la
 * conexión a SQL Server tiene que quedar guardada antes de que la app móvil
 * pueda hablar con nada. De ahí en adelante toda la administración (usuarios,
 * correo, notificaciones) se hace desde la app con el rol admin.
 */
class SetupController extends Controller
{
    /**
     * El formulario, precedido de la comprobación del servidor.
     *
     * Si falta algo imprescindible no se dibuja el formulario: rellenar siete
     * campos para que el «Instalar» conteste «could not find driver» es hacerle
     * perder el tiempo a quien está en el servidor de un cliente sin nada que
     * consultar.
     */
    public function show()
    {
        return view('setup.index', [
            'requisitos' => Requisitos::todas(),
            'listo' => Requisitos::listo(),
            'yaConfigurado' => SoftlandConfig::exists(),
            'baseActual' => SoftlandConfig::load()['database'] ?? null,
            // Sólo viene puesto cuando un intento anterior se topó con una base
            // a la que le falta algo. Ver `store()`.
            'compatibilidad' => session('compatibilidad'),
        ]);
    }

    public function store(Request $request, Notificador $notificador)
    {
        if (! Requisitos::listo()) {
            return back()->withInput()->withErrors([
                'host' => 'Al servidor le falta algo para poder instalar. Recarga la página y mira la lista.',
            ]);
        }

        $data = $request->validate([
            'host' => 'required|string|max:120',
            'port' => 'nullable|string|max:10',
            'database' => 'required|string|max:60',
            'sa_user' => 'required|string|max:60',
            'sa_password' => 'required|string|max:120',
            'softland_user' => 'required|string|max:60',
            'softland_password' => 'required|string|max:120',
        ]);

        $cfg = [
            'host' => $data['host'],
            'port' => ($data['port'] ?? null) ?: null,
            'database' => $data['database'],
            'username' => $data['sa_user'],
            'password' => $data['sa_password'],
            'schema' => 'ventas',
        ];

        // 1) ¿Responde SQL Server con estas credenciales?
        SoftlandConnection::probe($cfg);
        try {
            DB::connection('softland_probe')->select('SELECT 1 AS x');
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors([
                'host' => 'No se pudo conectar a SQL Server: '.$this->limpiar($e->getMessage()),
            ]);
        }

        // 2) Solo el administrador de Softland puede instalar.
        if (strtolower(trim($data['softland_user'])) !== 'softland') {
            return back()->withInput()->withErrors([
                'softland_user' => 'Solo el usuario administrador «softland» puede instalar el sistema.',
            ]);
        }

        try {
            $row = DB::connection('softland_probe')->selectOne(
                'SELECT PassWord FROM softland.wisusuarios WHERE Usuario = ?',
                [$data['softland_user']]
            );
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors([
                'database' => 'La base no parece ser una base Softland válida: '.$this->limpiar($e->getMessage()),
            ]);
        }

        if (! $row || ! SoftlandCipher::verify($row->PassWord ?? '', $data['softland_password'])) {
            return back()->withInput()->withErrors([
                'softland_password' => 'Usuario o contraseña de Softland incorrectos.',
            ]);
        }

        // 3) ¿Tiene esta base lo que la app usa?
        //
        // Se pregunta **antes** de guardar, porque guardar una conexión a una
        // base incompleta deja el servidor instalado y roto a la vez: la app
        // arranca, el vendedor entra, y el problema sale el día que alguien
        // intenta facturar. Cada empresa corre la versión de Softland que le
        // tocó y entre versiones cambian tablas y columnas.
        $informe = (new Compatibilidad('softland_probe'))->informe();

        if (! $informe['esenciales']) {
            return back()->withInput()
                ->with('compatibilidad', $informe)
                ->withErrors(['database' => 'A la base «'.$data['database'].'» le falta algo que la app necesita para funcionar. Abajo está el detalle.']);
        }

        // 4) Guardar cifrado y aplicar en caliente.
        SoftlandConfig::save($cfg);
        SoftlandConnection::apply($cfg);

        // 5) Crear el esquema `ventas` y correr las migraciones.
        Artisan::call('ventas:install');

        // 6) Registrar al administrador y dejar las reglas de notificación sembradas.
        DB::connection('softland')->table('ventas.usuario')->updateOrInsert(
            ['softland_user' => 'softland'],
            [
                'nombre' => 'Administrador Softland',
                'rol' => 'admin',
                'habilitado' => 1,
                'activo' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $notificador->sembrarReglas();

        // Lo que falta sin ser imprescindible no impide instalar, pero sí tiene
        // que decirse: una base sin las tablas del DTE sirve para cotizar y
        // vender, y quien instala necesita saber que no va a poder facturar
        // antes de que alguien lo descubra facturando.
        //
        // La redirección va relativa, como todas las que escribe el servidor:
        // `redirect('/setup/listo')` genera una dirección absoluta y detrás de
        // un proxy inverso sale con el esquema y la carpeta equivocados. Ver
        // `Rutas`. El flash sobrevive igual: la sesión se guarda al salir, sea
        // cual sea la respuesta.
        $request->session()->flash('limita', $informe['limita']);

        return Rutas::irA($request, 'setup/listo');
    }

    public function listo()
    {
        return view('setup.listo', [
            'base' => SoftlandConfig::load()['database'] ?? '',
            'limita' => session('limita', []),
        ]);
    }

    private function limpiar(string $msg): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $msg), 0, 180);
    }
}
