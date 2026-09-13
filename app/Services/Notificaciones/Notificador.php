<?php

namespace App\Services\Notificaciones;

use App\Models\Notificacion;
use App\Models\NotificacionRegla;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Punto único por donde salen los correos de la aplicación.
 *
 * Resuelve los destinatarios según la regla configurada para el evento, arma el
 * correo, lo envía y deja registro en `ventas.notificacion` pase lo que pase.
 *
 * Regla de oro: **notificar nunca puede voltear la operación**. Si el SMTP está
 * mal o el servidor no responde, se registra el error y la venta sigue su curso.
 */
class Notificador
{
    /**
     * Dispara un evento.
     *
     * @param  string  $evento  Una de las constantes de Eventos.
     * @param  array{asunto: string, cuerpo_html: string, referencia?: string|null}  $mensaje
     * @param  Usuario|null  $dueno  Vendedor del documento (destinatario "dueño").
     * @param  string|null  $emailCliente  Correo del contacto del cliente, si aplica.
     * @param  Usuario|null  $actor  Quién provocó el evento (para la bitácora).
     */
    public function disparar(
        string $evento,
        array $mensaje,
        ?Usuario $dueno = null,
        ?string $emailCliente = null,
        ?Usuario $actor = null,
    ): ?Notificacion {
        if (! Eventos::existe($evento)) {
            return null;
        }

        $regla = $this->regla($evento);
        if (! $regla->activa) {
            return null;
        }

        $destinos = $this->destinatarios($regla, $dueno, $emailCliente);
        if (! $destinos) {
            return null;
        }

        $log = Notificacion::on('softland')->create([
            'evento' => $evento,
            'referencia' => $mensaje['referencia'] ?? null,
            'destinatarios' => mb_substr(implode(', ', $destinos), 0, 1000),
            'asunto' => mb_substr($mensaje['asunto'], 0, 200),
            'estado' => 'pendiente',
            'usuario_id' => $actor?->id,
        ]);

        try {
            $html = $this->plantilla($mensaje['asunto'], $mensaje['cuerpo_html']);
            Mail::html($html, function ($m) use ($destinos, $mensaje) {
                $m->to($destinos[0])->subject($mensaje['asunto']);
                foreach (array_slice($destinos, 1) as $extra) {
                    $m->cc($extra);
                }
            });
            $log->forceFill(['estado' => 'enviada', 'enviada_at' => now()])->save();
        } catch (\Throwable $e) {
            report($e);
            $log->forceFill([
                'estado' => 'error',
                'error' => mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 500),
            ])->save();
        }

        return $log;
    }

    /** La regla del evento; si no existe todavía, se crea con los valores por defecto. */
    public function regla(string $evento): NotificacionRegla
    {
        $regla = NotificacionRegla::on('softland')->where('evento', $evento)->first();
        if ($regla) {
            return $regla;
        }

        $def = Eventos::catalogo()[$evento]['defaults'];

        return NotificacionRegla::on('softland')->create([
            'evento' => $evento,
            'activa' => true,
            'avisar_dueno' => (bool) ($def['avisar_dueno'] ?? false),
            'avisar_jefe' => (bool) ($def['avisar_jefe'] ?? false),
            'avisar_cliente' => (bool) ($def['avisar_cliente'] ?? false),
            'roles' => $def['roles'] ?? null,
            'copia_a' => null,
        ]);
    }

    /** Crea las reglas que falten, para que la app las liste completas. */
    public function sembrarReglas(): void
    {
        foreach (Eventos::todos() as $evento) {
            $this->regla($evento);
        }
    }

    /**
     * Resuelve la lista final de correos, sin repetidos y sin vacíos.
     *
     * @return string[]
     */
    protected function destinatarios(NotificacionRegla $regla, ?Usuario $dueno, ?string $emailCliente): array
    {
        $emails = [];

        if ($regla->avisar_dueno && $dueno?->email) {
            $emails[] = $dueno->email;
        }

        if ($regla->avisar_jefe && $dueno?->jefe_id) {
            $jefe = Usuario::on('softland')->find($dueno->jefe_id);
            if ($jefe?->email && $jefe->activo) {
                $emails[] = $jefe->email;
            }
        }

        if ($regla->avisar_cliente && $emailCliente) {
            $emails[] = $emailCliente;
        }

        foreach ($this->lista($regla->roles) as $rol) {
            $delRol = Usuario::on('softland')
                ->where('rol', $rol)->where('activo', true)
                ->whereNotNull('email')
                ->pluck('email')->all();
            $emails = array_merge($emails, $delRol);
        }

        $emails = array_merge($emails, $this->lista($regla->copia_a));

        $limpios = [];
        foreach ($emails as $e) {
            $e = trim((string) $e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && ! in_array($e, $limpios, true)) {
                $limpios[] = $e;
            }
        }

        return $limpios;
    }

    /** @return string[] */
    protected function lista(?string $csv): array
    {
        if (! $csv) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    /**
     * Envoltura HTML común a todos los correos: encabezado con el color de
     * Softland, cuerpo y pie. Inline CSS, que es lo único que respetan los
     * clientes de correo.
     */
    protected function plantilla(string $titulo, string $cuerpoHtml): string
    {
        $app = e((string) config('app.name'));

        return <<<HTML
        <div style="margin:0;padding:24px 0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">
          <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;
                      box-shadow:0 1px 4px rgba(0,0,0,.12);">
            <div style="background:#1d1060;color:#ffffff;padding:18px 24px;border-bottom:4px solid #26bdef;">
              <div style="font-size:17px;font-weight:bold;">{$app}</div>
            </div>
            <div style="padding:24px;color:#2b2b2b;font-size:14px;line-height:1.6;">
              <h2 style="margin:0 0 14px;font-size:16px;color:#1d1060;">{$titulo}</h2>
              {$cuerpoHtml}
            </div>
            <div style="padding:14px 24px;background:#f4f4f4;color:#919191;font-size:11px;">
              Mensaje automático de {$app}. No respondas a este correo.
            </div>
          </div>
        </div>
        HTML;
    }

    /** Últimas notificaciones, para la pantalla de diagnóstico del admin. */
    public function bitacora(int $limite = 50): array
    {
        return DB::connection('softland')
            ->table('ventas.notificacion')
            ->orderByDesc('id')->limit($limite)
            ->get(['id', 'evento', 'referencia', 'destinatarios', 'asunto', 'estado', 'error', 'enviada_at', 'created_at'])
            ->map(fn ($n) => (array) $n)->all();
    }
}
