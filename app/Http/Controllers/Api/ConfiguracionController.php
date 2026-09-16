<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dte\ReglasFactura;
use App\Services\Notificaciones\Eventos;
use App\Services\Notificaciones\Notificador;
use App\Support\MailConfig;
use App\Support\SoftlandCipher;
use App\Support\SoftlandConfig;
use App\Support\SoftlandConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Configuración del servidor desde la app (solo rol admin).
 *
 * Tres bloques: conexión a SQL Server, correo saliente y reglas de notificación.
 * Ninguna contraseña se devuelve nunca al cliente: solo una pista de que existe.
 */
class ConfiguracionController extends Controller
{
    public function __construct(private Notificador $notificador) {}

    public function index()
    {
        $cfg = SoftlandConfig::load() ?? [];
        $mail = MailConfig::load() ?? [];

        return response()->json([
            'conexion' => [
                'host' => $cfg['host'] ?? null,
                'port' => $cfg['port'] ?? null,
                'database' => $cfg['database'] ?? null,
                'schema' => $cfg['schema'] ?? 'ventas',
                'sa_user' => $cfg['username'] ?? null,
                'password_guardada' => ! empty($cfg['password']),
            ],
            'correo' => [
                'host' => $mail['host'] ?? '',
                'port' => $mail['port'] ?? '',
                'encryption' => $mail['encryption'] ?? 'tls',
                'username' => $mail['username'] ?? '',
                'from_address' => $mail['from_address'] ?? '',
                'from_name' => $mail['from_name'] ?? '',
                'password_guardada' => ! empty($mail['password']),
                'configurado' => ! empty($mail['host']),
            ],
            'facturacion' => [
                // Apagada, el receptor de la factura se hereda de la nota de
                // venta y el campo ni se enseña. Encendida, habilita el ciclo de
                // distribuidor: facturarle la comisión a otro RUT.
                'receptor_editable' => (new ReglasFactura)->receptorEditable(),
            ],
        ]);
    }

    /**
     * Enciende o apaga que el receptor de una factura se pueda cambiar.
     *
     * Apagada —como nace— el cliente de la cotización, el de la nota de venta y
     * el de la factura son el mismo RUT. Encendida, quien factura puede cambiar
     * el receptor: es lo que hace posible el ciclo de distribuidor, donde la
     * nota de venta registra la venta al cliente final y la factura le cobra la
     * comisión a otra empresa.
     */
    public function guardarFacturacion(Request $request)
    {
        $data = $request->validate(['receptor_editable' => 'required|boolean']);

        (new ReglasFactura)->fijarReceptorEditable((bool) $data['receptor_editable']);

        return response()->json(['receptor_editable' => (bool) $data['receptor_editable']]);
    }

    /**
     * Cambia la conexión a SQL Server. Exige la contraseña del usuario
     * «softland» para autorizar, y prueba la conexión ANTES de guardar nada.
     */
    public function guardarConexion(Request $request)
    {
        $data = $request->validate([
            'host' => 'required|string|max:120',
            'port' => 'nullable|string|max:10',
            'database' => 'required|string|max:60',
            'sa_user' => 'required|string|max:60',
            'sa_password' => 'nullable|string|max:120',       // en blanco = mantener
            'softland_password' => 'required|string|max:120',  // autoriza el cambio
        ]);

        $actual = SoftlandConfig::load() ?? [];
        $password = ($data['sa_password'] ?? '') !== '' ? $data['sa_password'] : ($actual['password'] ?? null);
        if (! $password) {
            return response()->json(['message' => 'Falta la contraseña del usuario SQL.'], 422);
        }

        $cfg = [
            'host' => $data['host'],
            'port' => ($data['port'] ?? null) ?: null,
            'database' => $data['database'],
            'username' => $data['sa_user'],
            'password' => $password,
            'schema' => 'ventas',
        ];

        SoftlandConnection::probe($cfg);
        try {
            DB::connection('softland_probe')->select('SELECT 1 AS x');
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'No se pudo conectar a SQL Server: '.$this->limpiar($e->getMessage()),
            ], 422);
        }

        try {
            $row = DB::connection('softland_probe')->selectOne(
                'SELECT PassWord FROM softland.wisusuarios WHERE Usuario = ?', ['softland']
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'La base no parece ser Softland: '.$this->limpiar($e->getMessage()),
            ], 422);
        }

        if (! $row || ! SoftlandCipher::verify($row->PassWord ?? '', $data['softland_password'])) {
            return response()->json(['message' => 'Contraseña de Softland incorrecta.'], 422);
        }

        SoftlandConfig::save($cfg);
        SoftlandConnection::apply($cfg);

        return response()->json(['ok' => true, 'message' => 'Conexión guardada.']);
    }

    /** Guarda el SMTP de la instalación (cifrado en disco) y lo aplica en caliente. */
    public function guardarCorreo(Request $request)
    {
        $data = $request->validate($this->reglasCorreo());

        MailConfig::save($this->armarCorreo($data));
        MailConfig::apply();

        return response()->json(['ok' => true, 'message' => 'Configuración de correo guardada.']);
    }

    /** Manda un correo de prueba con los datos del formulario, sin guardarlos. */
    public function probarCorreo(Request $request)
    {
        $data = $request->validate($this->reglasCorreo(conDestino: true));

        MailConfig::apply($this->armarCorreo($data));

        try {
            Mail::html(
                '<p>Correo de prueba de <b>'.e((string) config('app.name')).'</b>.</p>'
                .'<p>Si te llegó, el SMTP quedó bien configurado.</p>',
                fn ($m) => $m->to($data['to'])->subject('Prueba de correo — '.config('app.name'))
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'No se pudo enviar: '.$this->limpiar($e->getMessage()),
            ], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Correo de prueba enviado a '.$data['to'].'.']);
    }

    /** Catálogo de eventos + la regla configurada para cada uno. */
    public function notificaciones()
    {
        $this->notificador->sembrarReglas();

        $reglas = DB::connection('softland')->table('ventas.notificacion_regla')
            ->get()->keyBy('evento');

        $items = [];
        foreach (Eventos::catalogo() as $evento => $def) {
            $r = $reglas[$evento] ?? null;
            $items[] = [
                'evento' => $evento,
                'label' => $def['label'],
                'descripcion' => $def['descripcion'],
                'fase' => $def['fase'],
                'activa' => (bool) ($r->activa ?? true),
                'avisar_dueno' => (bool) ($r->avisar_dueno ?? false),
                'avisar_jefe' => (bool) ($r->avisar_jefe ?? false),
                'avisar_cliente' => (bool) ($r->avisar_cliente ?? false),
                'roles' => $r->roles ?? null,
                'copia_a' => $r->copia_a ?? null,
            ];
        }

        return response()->json(['eventos' => $items]);
    }

    public function guardarNotificacion(Request $request, string $evento)
    {
        if (! Eventos::existe($evento)) {
            return response()->json(['message' => 'Evento desconocido.'], 404);
        }

        $data = $request->validate([
            'activa' => 'boolean',
            'avisar_dueno' => 'boolean',
            'avisar_jefe' => 'boolean',
            'avisar_cliente' => 'boolean',
            'roles' => 'nullable|string|max:120',
            'copia_a' => 'nullable|string|max:500',
        ]);

        foreach ($this->correos($data['copia_a'] ?? null) as $correo) {
            if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['message' => "«{$correo}» no es un correo válido."], 422);
            }
        }

        $regla = $this->notificador->regla($evento);
        $regla->fill($data)->save();

        return response()->json(['ok' => true]);
    }

    /** Bitácora: qué correos salieron, a quién y si fallaron. */
    public function bitacora(Request $request)
    {
        $limite = min(200, max(10, (int) $request->query('limite', 50)));

        return response()->json(['notificaciones' => $this->notificador->bitacora($limite)]);
    }

    /** @return string[] */
    private function correos(?string $csv): array
    {
        return $csv ? array_values(array_filter(array_map('trim', explode(',', $csv)))) : [];
    }

    private function reglasCorreo(bool $conDestino = false): array
    {
        return array_merge([
            'host' => 'required|string|max:150',
            'port' => 'nullable|string|max:6',
            'encryption' => 'nullable|in:ssl,tls,none',
            'username' => 'nullable|string|max:150',
            'password' => 'nullable|string|max:200',
            'from_address' => 'required|email|max:150',
            'from_name' => 'nullable|string|max:120',
        ], $conDestino ? ['to' => 'required|email'] : []);
    }

    /** Normaliza el formulario SMTP; contraseña en blanco = mantener la guardada. */
    private function armarCorreo(array $data): array
    {
        $actual = MailConfig::load() ?? [];
        $pass = ($data['password'] ?? '') !== '' ? $data['password'] : ($actual['password'] ?? null);

        return [
            'host' => $data['host'],
            'port' => ($data['port'] ?? null) ?: null,
            'encryption' => ($data['encryption'] ?? null) === 'none' ? null : ($data['encryption'] ?? null),
            'username' => ($data['username'] ?? null) ?: null,
            'password' => $pass,
            'from_address' => $data['from_address'],
            'from_name' => ($data['from_name'] ?? null) ?: null,
        ];
    }

    private function limpiar(string $msg): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $msg), 0, 180);
    }
}
