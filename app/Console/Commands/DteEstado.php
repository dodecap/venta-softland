<?php

namespace App\Console\Commands;

use App\Services\Dte\Certificado;
use App\Services\Dte\Sii;
use App\Services\Dte\TipoDte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * En qué quedó un envío, o un documento.
 *
 * Son dos preguntas distintas y conviene no confundirlas:
 *
 *   - **el envío** (`--track`) — si el SII recibió el sobre y qué hizo con él.
 *     Recién mandado contesta «en proceso»: eso no es un error, es que todavía
 *     no lo mira.
 *   - **el documento** (`--folio`) — si el SII tiene registrado ese folio con
 *     esos datos. Es la pregunta que vale para saber si la factura existe, y se
 *     puede hacer meses después.
 *
 *   php artisan dte:estado --track=12399485286
 *   php artisan dte:estado --folio=234
 */
class DteEstado extends Command
{
    protected $signature = 'dte:estado
        {--track= : TrackID de un envío}
        {--folio= : Folio de un documento}
        {--tipo=33 : Tipo de DTE del SII}
        {--ambiente= : produccion | certificacion}';

    protected $description = 'Consulta al SII el estado de un envío o de un documento';

    public function handle(): int
    {
        $tipo = TipoDte::tryFrom((int) $this->option('tipo'));

        if (! $tipo) {
            $this->error('Tipo de DTE desconocido.');

            return self::FAILURE;
        }

        try {
            $cert = Certificado::desdeConfiguracion();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $sii = new Sii($cert, trim((string) $this->option('ambiente')) ?: null);
        $emisor = DB::connection('softland')->table('softland.soempre')->first();
        $rutEmisor = strtoupper(trim((string) ($emisor->RutEmisor ?? '')));

        $track = trim((string) $this->option('track'));
        $folio = (int) $this->option('folio');

        if ($track === '' && $folio === 0) {
            $this->error('Hay que decir qué consultar: --track de un envío o --folio de un documento.');

            return self::FAILURE;
        }

        try {
            return $track !== ''
                ? $this->delEnvio($sii, $track, $rutEmisor)
                : $this->delDocumento($sii, $tipo, $folio, $rutEmisor);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function delEnvio(Sii $sii, string $track, string $rutEmisor): int
    {
        $r = $sii->estadoEnvio($track, $rutEmisor);

        $this->line("Envío <options=bold>{$track}</>");
        $this->line("  estado: {$r['estado']}".($r['glosa'] !== '' ? " — {$r['glosa']}" : ''));

        foreach (['aceptados' => 'aceptados', 'rechazados' => 'rechazados', 'reparos' => 'con reparos'] as $clave => $texto) {
            $r[$clave] === null || $this->line("  {$texto}: {$r[$clave]}");
        }

        // «EPR» es «envío procesado»: el SII terminó de mirarlo. Lo que diga de
        // cada documento va en los contadores de arriba.
        return in_array($r['estado'], ['EPR', 'DOK'], true) ? self::SUCCESS : self::FAILURE;
    }

    private function delDocumento(Sii $sii, TipoDte $tipo, int $folio, string $rutEmisor): int
    {
        $cab = DB::connection('softland')->table('softland.dte_doccab')
            ->where('TipoDTE', $tipo->value)->where('Folio', $folio)->first();

        if (! $cab) {
            $this->error("No está el folio {$folio} de {$tipo->nombre()} en dte_doccab.");

            return self::FAILURE;
        }

        $r = $sii->estadoDocumento(
            $rutEmisor,
            $tipo,
            $folio,
            (string) $cab->RUTRecep,
            (string) $cab->FchEmis,
            (int) round((float) $cab->MntTotal),
        );

        $this->line("{$tipo->nombre()} folio <options=bold>{$folio}</>");
        $this->line("  receptor: {$cab->RUTRecep}   total: ".number_format((float) $cab->MntTotal, 0, ',', '.'));
        $this->line("  estado:   {$r['estado']}".($r['glosa'] !== '' ? " — {$r['glosa']}" : ''));

        return str_starts_with($r['estado'], 'DOK') ? self::SUCCESS : self::FAILURE;
    }
}
