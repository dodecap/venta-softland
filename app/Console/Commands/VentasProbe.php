<?php

namespace App\Console\Commands;

use App\Services\Softland\Catalogos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnóstico de la instalación: conexión, esquema propio y maestros de Softland
 * que necesita el flujo de ventas. No imprime contraseñas.
 *
 *   php artisan ventas:probe
 */
class VentasProbe extends Command
{
    protected $signature = 'ventas:probe';

    protected $description = 'Verifica la conexión a Softland y los maestros del flujo de ventas';

    public function handle(Catalogos $catalogos): int
    {
        $conn = DB::connection('softland');

        try {
            $conn->select('SELECT 1 AS x');
            $this->info('Conexión OK — base: '.$conn->getDatabaseName());
        } catch (\Throwable $e) {
            $this->error('No se pudo conectar: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<options=bold>Tablas del flujo de ventas en Softland</>');
        $tablas = [
            'softland.nwcotiza' => 'Cotizaciones (encabezado)',
            'softland.nwdetcot' => 'Cotizaciones (detalle)',
            'softland.nw_nventa' => 'Notas de venta (encabezado)',
            'softland.nw_detnv' => 'Notas de venta (detalle)',
            'softland.iw_gsaen' => 'Documentos de venta (encabezado)',
            'softland.iw_gmovi' => 'Documentos de venta (detalle)',
            'softland.dte_doccab' => 'DTE (encabezado)',
            'softland.dte_siicaf' => 'Folios CAF',
            'softland.iw_tprod' => 'Productos',
            'softland.cwtauxi' => 'Clientes',
            'softland.cwtvend' => 'Vendedores',
        ];
        foreach ($tablas as $tabla => $desc) {
            try {
                $n = $conn->table($tabla)->count();
                $this->line(sprintf('  %-22s %-34s %8s filas', $tabla, $desc, number_format($n, 0, ',', '.')));
            } catch (\Throwable $e) {
                $this->line(sprintf('  %-22s %-34s <fg=red>NO ACCESIBLE</>', $tabla, $desc));
            }
        }

        $this->newLine();
        $this->line('<options=bold>Esquema propio de la app</>');
        foreach (['usuario', 'api_token', 'config', 'notificacion_regla', 'notificacion'] as $t) {
            try {
                $n = $conn->table("ventas.$t")->count();
                $this->line(sprintf('  %-22s %8d filas', "ventas.$t", $n));
            } catch (\Throwable $e) {
                $this->line(sprintf('  %-22s <fg=red>no existe (¿falta ventas:install?)</>', "ventas.$t"));
            }
        }

        $this->newLine();
        $this->line('<options=bold>Folios CAF disponibles por tipo de documento</>');
        try {
            $caf = $conn->select(
                'SELECT DocCod, MIN(FolioD) AS desde, MAX(FolioH) AS hasta, COUNT(*) AS lotes
                 FROM softland.dte_siicaf GROUP BY DocCod ORDER BY DocCod'
            );
            // `DocCod` es char y viene con relleno; sin trim ningún código calza.
            $vistos = [];
            foreach ($caf as $c) {
                $cod = trim((string) $c->DocCod);
                $vistos[] = $cod;
                $this->line(sprintf('  DTE %-4s folios %s a %s (%d lotes)', $cod, $c->desde, $c->hasta, $c->lotes));
            }
            // Ojo con las claves: PHP convierte '33' a int 33, así que el código
            // que se compara tiene que volver a string o la comparación estricta
            // falla siempre y el aviso sale aunque haya folios.
            $esperados = ['33' => 'factura electrónica', '39' => 'boleta electrónica', '61' => 'nota de crédito'];
            foreach ($esperados as $cod => $nom) {
                if (! in_array((string) $cod, $vistos, true)) {
                    $this->line("  <fg=yellow>DTE {$cod} ({$nom}): sin CAF cargado — no se puede emitir</>");
                }
            }
        } catch (\Throwable $e) {
            $this->line('  <fg=red>No se pudo leer dte_siicaf</>');
        }

        $this->newLine();
        $this->line('<options=bold>Maestros</>');
        $this->line(sprintf('  vendedores: %d   bodegas: %d   listas de precio: %d   centros de costo: %d',
            count($catalogos->vendedores()), count($catalogos->bodegas()),
            count($catalogos->listasPrecio()), count($catalogos->centrosCosto())));
        $this->line('  RUT emisor (desde CAF): '.($catalogos->rutEmisor() ?: 'no determinado'));

        return self::SUCCESS;
    }
}
