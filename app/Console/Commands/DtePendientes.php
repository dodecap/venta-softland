<?php

namespace App\Console\Commands;

use App\Services\Dte\Certificado;
use App\Services\Dte\Emision;
use App\Services\Dte\Facturacion;
use App\Services\Dte\ReglasFactura;
use App\Services\Dte\Sii;
use App\Services\Dte\TipoDte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La cola del servidor: manda lo que quedó escrito sin viajar, y recoge los
 * veredictos de lo que viajó.
 *
 * ## Por qué existe
 *
 * Con el envío automático encendido, un documento escrito y sin TrackID es una
 * avería: sólo puede haber pasado una de dos cosas —el SII no contestó, o el
 * servidor se cayó entre escribir y mandar— y las dos se arreglan reintentando.
 *
 * Con el envío en manual **no manda nada**, y sigue recogiendo veredictos. Si
 * mandara, la llave no serviría de nada: lo que alguien dejó a propósito sin
 * enviar saldría solo cinco minutos después.
 *
 * Reintentar a mano exige que alguien se acuerde, y ésa es justamente la
 * memoria que no queremos que sostenga el sistema. Por eso esto corre solo, con
 * el programador de tareas de Windows.
 *
 * ## Las dos mitades
 *
 * **Mandar** lo que no salió, y **preguntar** por lo que salió y todavía no
 * tiene respuesta. La segunda mitad es la que hace que el verde aparezca solo:
 * el SII no avisa de nada, y hasta que alguien pregunta, no se sabe.
 *
 * ## Lo que no hace
 *
 * No toca documentos que no sean de esta app ni anulados, no reenvía nada que
 * ya tenga TrackID —un folio no se manda dos veces— y no se inventa folios.
 *
 *   php artisan dte:pendientes           # manda y pregunta
 *   php artisan dte:pendientes --ver     # sólo cuenta lo que haría
 */
class DtePendientes extends Command
{
    protected $signature = 'dte:pendientes
        {--ver : Enseña lo que haría y no manda nada}
        {--dias=30 : Hasta cuántos días atrás mirar}
        {--tope=20 : Cuántos documentos mandar como mucho en una pasada}';

    protected $description = 'Manda al SII lo escrito que no viajó, y recoge los veredictos pendientes';

    public function handle(): int
    {
        $desde = date('Y-m-d', strtotime('-'.(int) $this->option('dias').' days'));

        $porMandar = $this->porMandar($desde);
        $porPreguntar = $this->porPreguntar($desde);

        $this->line("Por mandar: {$porMandar->count()} · por preguntar: {$porPreguntar->count()}");

        if ($this->option('ver')) {
            foreach ($porMandar as $d) {
                $this->line("  mandar    {$d->Tipo} folio {$d->Folio} ({$d->Fecha})");
            }
            foreach ($porPreguntar as $d) {
                $this->line("  preguntar {$d->Tipo} folio {$d->Folio} TrackID {$d->TrackID}");
            }

            return self::SUCCESS;
        }

        if ($porMandar->isEmpty() && $porPreguntar->isEmpty()) {
            return self::SUCCESS;
        }

        try {
            $cert = Certificado::desdeConfiguracion();
        } catch (Throwable $e) {
            $this->error('Sin certificado no hay nada que hacer: '.$e->getMessage());

            return self::FAILURE;
        }

        // Un certificado vencido no es un error del que reintentar salga: hasta
        // que lo renueven no emite ni esta app ni el ERP.
        if ($cert->vencido()) {
            $this->error((string) $cert->avisaVencimiento());

            return self::FAILURE;
        }

        // Con el envío en manual esta tarea **no manda nada**. Si mandara,
        // la llave no serviría de nada: lo que alguien dejó a propósito sin
        // enviar saldría solo cinco minutos después. Preguntar por lo que ya
        // viajó sí se sigue haciendo — eso no le quita la decisión a nadie.
        $automatico = (new ReglasFactura)->envioAutomatico();

        if (! $automatico && $porMandar->isNotEmpty()) {
            $this->line('  el envío está en manual: no se manda nada, sólo se recogen veredictos');
        }

        $fallos = $automatico ? $this->mandar($cert, $porMandar) : 0;
        $this->preguntar($cert, $porPreguntar);

        return $fallos === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Lo escrito que no tiene TrackID.
     *
     * Se mira `iw_gsaen` y no `dte_doccab` porque la fila de seguimiento la
     * deja el repartidor de folios al entregar el número: existe siempre, con
     * TrackID vacío, desde antes de que el documento esté terminado.
     */
    private function porMandar(string $desde)
    {
        return DB::connection('softland')->table('softland.iw_gsaen as s')
            ->leftJoin('softland.dte_doccab as d', function ($j) {
                $j->on('d.Tipo', 's.Tipo')->on('d.NroInt', 's.NroInt');
            })
            ->whereIn('s.Tipo', ['F', 'N'])
            ->where('s.Fecha', '>=', $desde)
            ->where('s.Estado', '<>', 'N')
            ->where('s.Folio', '>', 0)
            // Sólo lo que escribió esta app: las facturas del Softland de
            // escritorio las manda el Softland de escritorio, y meterse a
            // mandarlas nosotros sería disputarle documentos que no son
            // nuestros.
            ->where('s.Proceso', Facturacion::PROCESO)
            ->where(fn ($q) => $q->whereNull('d.TrackID')->orWhereIn('d.TrackID', ['', '0']))
            ->orderBy('s.Fecha')
            ->limit((int) $this->option('tope'))
            ->get(['s.Tipo', 's.NroInt', 's.Folio', 's.Fecha', 's.SubTipoDocto']);
    }

    /** Lo que viajó y todavía no tiene veredicto anotado. */
    private function porPreguntar(string $desde)
    {
        return DB::connection('softland')->table('softland.dte_doccab as d')
            ->join('softland.iw_gsaen as s', function ($j) {
                $j->on('d.Tipo', 's.Tipo')->on('d.NroInt', 's.NroInt');
            })
            ->where('s.Fecha', '>=', $desde)
            ->where('s.Proceso', Facturacion::PROCESO)
            ->whereNotNull('d.TrackID')
            ->whereNotIn('d.TrackID', ['', '0'])
            // Ni aceptado ni con motivo escrito: nadie ha preguntado todavía, o
            // la última vez el SII seguía procesándolo.
            ->where(fn ($q) => $q->whereNull('d.AceptadoSII')->orWhere('d.AceptadoSII', 0))
            ->whereNull('d.Motivo')
            ->orderBy('s.Fecha')
            ->limit((int) $this->option('tope'))
            ->get(['d.Tipo', 'd.NroInt', 'd.Folio', 'd.TrackID', 's.SubTipoDocto']);
    }

    private function mandar(Certificado $cert, $documentos): int
    {
        $emision = new Emision($cert);
        $fallos = 0;

        foreach ($documentos as $d) {
            try {
                $r = $emision->emitir(trim((string) $d->Tipo), (int) $d->NroInt);
                $this->info("  enviado {$d->Tipo} folio {$d->Folio} · TrackID {$r['trackId']}");
            } catch (Throwable $e) {
                $fallos++;
                // Se sigue con los demás: que el SII rechace uno no es motivo
                // para dejar los otros sin mandar.
                $this->error("  falló {$d->Tipo} folio {$d->Folio}: ".$e->getMessage());
            }
        }

        return $fallos;
    }

    private function preguntar(Certificado $cert, $documentos): void
    {
        $emision = new Emision($cert);
        $sii = new Sii($cert);
        $rut = strtoupper(trim((string) DB::connection('softland')
            ->table('softland.soempre')->value('RutEmisor')));

        foreach ($documentos as $d) {
            $tipo = TipoDte::desdeSoftland(trim((string) $d->Tipo), trim((string) $d->SubTipoDocto));

            if (! $tipo) {
                continue;
            }

            try {
                $envio = $sii->estadoEnvio(trim((string) $d->TrackID), $rut);
            } catch (Throwable $e) {
                $this->warn("  no se pudo preguntar por el folio {$d->Folio}: ".$e->getMessage());

                continue;
            }

            // Mientras el SII lo está mirando no se anota nada: escribir
            // «rechazado» porque todavía no hay respuesta sería peor que
            // esperar a la pasada siguiente.
            if (! in_array($envio['estado'], ['EPR', 'DOK'], true)) {
                $this->line("  folio {$d->Folio}: {$envio['estado']} — el SII sigue mirándolo");

                continue;
            }

            $aceptado = (int) $envio['rechazados'] === 0 && (int) $envio['aceptados'] > 0;

            $emision->anotarVeredicto($tipo, (int) $d->Folio, $aceptado, trim(
                $envio['glosa'].' · rechazados '.$envio['rechazados'].', con reparos '.$envio['reparos']
            ));

            $this->line("  folio {$d->Folio}: ".($aceptado ? 'aceptada' : 'rechazada')." ({$envio['glosa']})");
        }
    }
}
