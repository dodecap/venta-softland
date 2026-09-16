<?php

namespace App\Console\Commands;

use App\Services\Dte\Certificado;
use App\Services\Dte\Emision;
use App\Services\Dte\Sobre;
use App\Services\Dte\TipoDte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Manda un documento al SII.
 *
 * **Es el único comando de la app que hace algo que no se deshace.** De ahí las
 * tres barreras, que no son ceremonia:
 *
 *   - sin `--confirmar` no manda nada: arma el sobre, lo enseña y para. Eso es
 *     un ensayo completo —se recorre todo el camino menos el último paso— y es
 *     lo que conviene mirar antes de emitir el primero de verdad.
 *   - enseña qué va a mandar, a quién y por cuánto, y lo pregunta.
 *   - se niega si el folio ya tiene `TrackID`: un folio no se manda dos veces.
 *
 *   php artisan dte:envia F 202              # ensayo, no manda
 *   php artisan dte:envia F 202 --confirmar  # manda de verdad
 */
class DteEnvia extends Command
{
    protected $signature = 'dte:envia
        {tipo : Tipo en Softland: F factura, N nota de crédito}
        {nroint : Número interno del documento en iw_gsaen}
        {--confirmar : Manda de verdad. Sin esto solo se ensaya}
        {--guardar= : Escribe el sobre en un archivo, para mirarlo}
        {--ambiente= : produccion | certificacion}';

    protected $description = 'Envía al SII un documento ya escrito en inventario y facturación';

    public function handle(): int
    {
        $tipoSoftland = strtoupper((string) $this->argument('tipo'));
        $nroInt = (int) $this->argument('nroint');

        try {
            $cert = Certificado::desdeConfiguracion();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $cab = DB::connection('softland')->table('softland.iw_gsaen')
            ->where('Tipo', $tipoSoftland)->where('NroInt', $nroInt)->first();

        if (! $cab) {
            $this->error("No está el documento {$tipoSoftland}/{$nroInt} en iw_gsaen.");

            return self::FAILURE;
        }

        $tipo = TipoDte::desdeSoftland($cab->Tipo, $cab->SubTipoDocto);

        if (! $tipo) {
            $this->error("El documento {$cab->Tipo}/{$cab->SubTipoDocto} no es electrónico.");

            return self::FAILURE;
        }

        $ambiente = trim((string) $this->option('ambiente')) ?: (string) config('dte.ambiente');
        $emision = new Emision($cert, $ambiente);

        $this->line("Documento:   <options=bold>{$tipo->nombre()} folio {$cab->Folio}</>  ({$cab->Tipo}/{$cab->NroInt})");
        $this->line('Fecha:       '.substr((string) $cab->Fecha, 0, 10));
        $this->line("Receptor:    {$cab->CodAux}");
        $this->line('Total:       $ '.number_format(abs((float) $cab->Total), 0, ',', '.'));
        $this->line("Quien firma: {$cert->sujeto} ({$cert->rut})");
        $this->line("Ambiente:    <options=bold>{$ambiente}</>");
        $this->newLine();

        if ($ya = $emision->seguimiento($tipo, (int) $cab->Folio)) {
            $this->error("Este folio ya se envió al SII: TrackID {$ya}. Un folio no se manda dos veces.");

            return self::FAILURE;
        }

        // El ensayo arma y firma el sobre entero. Si algo falta, se sabe aquí.
        try {
            $sobre = (new Sobre($cert))->armar([['tipo' => $tipoSoftland, 'nroInt' => $nroInt]]);
        } catch (Throwable $e) {
            $this->error('No se pudo armar el sobre: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("Sobre armado y firmado: {$sobre['id']}, ".strlen($sobre['xml']).' bytes');

        if ($destino = trim((string) $this->option('guardar'))) {
            file_put_contents($destino, $sobre['xml']);
            $this->line("  escrito en {$destino}");
        }

        if (! $this->option('confirmar')) {
            $this->newLine();
            $this->warn('Ensayo: no se mandó nada. Para enviarlo de verdad, agregar --confirmar.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Enviar el folio {$cab->Folio} al SII en {$ambiente}. Esto no se deshace. ¿Seguir?", false)) {
            $this->line('No se mandó nada.');

            return self::SUCCESS;
        }

        try {
            $r = $emision->emitir($tipoSoftland, $nroInt);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Enviado. TrackID <options=bold>{$r['trackId']}</>");
        $r['glosa'] === '' || $this->line("  {$r['glosa']}");
        $this->newLine();
        $this->line("El veredicto tarda. Se consulta con:  php artisan dte:estado --track={$r['trackId']}");

        return self::SUCCESS;
    }
}
