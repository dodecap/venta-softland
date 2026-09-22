<?php

namespace App\Console\Commands;

use App\Services\Dte\AlmacenCertificado;
use App\Services\Dte\Certificado;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Ver y cambiar el certificado digital de la empresa, desde el servidor.
 *
 *   php artisan dte:certificado
 *   php artisan dte:certificado --archivo=C:\ruta\firma.pfx
 *   php artisan dte:certificado --quitar
 *
 * Lo normal es hacerlo desde la app, en Configuración → Certificado digital.
 * Esto está para el día en que la app no se puede abrir —una instalación recién
 * hecha, un servidor que hay que dejar emitiendo antes de repartir teléfonos— y
 * para comprobar sin tocar nada.
 *
 * **La clave no se pasa como argumento**: se pregunta, y lo que se teclea no se
 * ve ni queda en el historial del intérprete de comandos.
 */
class DteCertificado extends Command
{
    protected $signature = 'dte:certificado
        {--archivo= : Ruta al .pfx o .p12 que se quiere dejar puesto}
        {--quitar : Olvida el certificado subido y vuelve al del .env, si lo hay}';

    protected $description = 'Muestra o cambia el certificado digital con que se firma el DTE';

    public function handle(): int
    {
        if ($this->option('quitar')) {
            AlmacenCertificado::olvidar();
            $this->info('Certificado quitado.');
            $this->newLine();
        }

        if ($ruta = $this->option('archivo')) {
            if (! is_file($ruta)) {
                $this->error("No está el archivo «{$ruta}».");

                return self::FAILURE;
            }

            $clave = (string) $this->secret('Clave del certificado');

            try {
                $ficha = AlmacenCertificado::guardar(
                    new UploadedFile($ruta, basename($ruta), null, null, true),
                    $clave,
                    'consola'
                );
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info('Guardado: '.$ficha['sujeto'].' ('.$ficha['rut'].'), vence el '.$ficha['vence'].'.');
            $this->newLine();
        }

        return $this->mostrar();
    }

    private function mostrar(): int
    {
        try {
            $cert = Certificado::desdeConfiguracion();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('<options=bold>Certificado digital en uso</>');
        $this->line('  Firma      '.$cert->sujeto);
        $this->line('  RUT        '.($cert->rut ?: '(no se pudo leer)'));
        $this->line('  Vence      '.$cert->vence.'  ('.$cert->diasRestantes().' días)');
        $this->line('  Origen     '.(AlmacenCertificado::hay()
            ? 'subido desde la app'
            : 'DTE_CERT_RUTA, en el .env del servidor'));

        if ($r = AlmacenCertificado::resumen()) {
            $this->line('  Lo subió   '.$r['subido_por'].' el '.$r['subido_en']);
        }

        if ($aviso = $cert->avisaVencimiento()) {
            $this->newLine();
            $cert->vencido() ? $this->error($aviso) : $this->warn($aviso);
        }

        return $cert->vencido() ? self::FAILURE : self::SUCCESS;
    }
}
