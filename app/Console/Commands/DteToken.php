<?php

namespace App\Console\Commands;

use App\Services\Dte\Certificado;
use App\Services\Dte\Sii;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pide una semilla y la canjea por un token.
 *
 * Es la comprobación completa del camino de autenticación **sin emitir nada ni
 * gastar un folio**: si el SII devuelve un token, entonces la conexión existe,
 * el certificado abre, la firma es válida y quien firma está autorizado ante el
 * SII para este contribuyente. Eso es casi todo lo que puede fallar.
 *
 * El token dura unos minutos, así que no se guarda ni sirve de nada anotarlo.
 * Aquí se muestra recortado: es una credencial, aunque sea efímera.
 *
 *   php artisan dte:token
 */
class DteToken extends Command
{
    protected $signature = 'dte:token {--ambiente= : produccion | certificacion}';

    protected $description = 'Comprueba el camino al SII pidiendo semilla y token, sin emitir nada';

    public function handle(): int
    {
        try {
            $cert = Certificado::desdeConfiguracion();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $sii = new Sii($cert, trim((string) $this->option('ambiente')) ?: null);

        $this->line("Ambiente:    <options=bold>{$sii->ambiente()}</>");
        $this->line("Certificado: {$cert->sujeto}".($cert->rut ? " ({$cert->rut})" : '').", vence {$cert->vence}");

        if ($aviso = $cert->avisaVencimiento()) {
            $this->warn("  {$aviso}");
        }

        if ($cert->rut === '') {
            $this->error('El certificado no trae el RUT de quien firma. Sin eso el SII no acepta el sobre.');

            return self::FAILURE;
        }

        $this->newLine();

        try {
            $this->line('Pidiendo semilla a '.$sii->direccion('semilla').' ...');
            $semilla = $sii->semilla();
            $this->line("  semilla: <fg=green>{$semilla}</>");

            $this->line('Canjeándola por token en '.$sii->direccion('token').' ...');
            $token = $sii->token();
            $this->line('  token:   <fg=green>'.substr($token, 0, 4).str_repeat('·', max(0, strlen($token) - 4)).'</>');
        } catch (Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('El SII reconoce el certificado. El camino de autenticación funciona.');

        return self::SUCCESS;
    }
}
