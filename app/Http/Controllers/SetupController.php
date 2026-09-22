<?php

namespace App\Http\Controllers;

use App\Services\Notificaciones\Notificador;
use App\Support\Requisitos;
use App\Support\SoftlandCipher;
use App\Support\SoftlandConfig;
use App\Support\SoftlandConnection;
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

        // 3) Guardar cifrado y aplicar en caliente.
        SoftlandConfig::save($cfg);
        SoftlandConnection::apply($cfg);

        // 4) Crear el esquema `ventas` y correr las migraciones.
        Artisan::call('ventas:install');

        // 5) Registrar al administrador y dejar las reglas de notificación sembradas.
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

        return redirect('/setup/listo');
    }

    public function listo()
    {
        return view('setup.listo', [
            'base' => SoftlandConfig::load()['database'] ?? '',
        ]);
    }

    private function limpiar(string $msg): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $msg), 0, 180);
    }
}
