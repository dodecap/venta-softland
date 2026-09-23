<?php

namespace App\Console\Commands;

use App\Services\Dte\Facturacion;
use App\Services\Sii\Traduccion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Qué le hizo esta app a la base del cliente.
 *
 * ## Por qué existe
 *
 * Porque es la primera pregunta de quien administra la base de un ERP en el que
 * se factura, y hasta ahora la respuesta estaba repartida entre diez
 * migraciones y la memoria de quien las escribió. Instalar algo dentro de la
 * base de producción de Softland sin poder enseñar la huella en treinta
 * segundos es pedir un acto de fe.
 *
 * ## Qué contesta, y qué no
 *
 * Contesta **lo que se puede medir ahora mismo**: qué objetos existen en el
 * esquema `ventas`, si las migraciones están todas puestas, si hay alguna clave
 * foránea que cruce al esquema `softland` y cuántas filas ha escrito la app en
 * tablas del ERP.
 *
 * No intenta demostrar que nadie modificó una tabla de Softland: eso no se
 * deduce de la base —cualquiera pudo hacerlo desde el escritorio, y el ERP crea
 * tablas suyas de nombre raro continuamente—, se deduce del código, y ahí está
 * escrito una sola vez: **ninguna migración hace `ALTER` sobre `softland`**.
 * Lo que sí se comprueba aquí es la consecuencia práctica: sin claves foráneas
 * que crucen, borrar el esquema `ventas` no puede arrastrar nada del ERP.
 *
 * No escribe nada. Se puede correr las veces que haga falta.
 *
 *   php artisan ventas:huella
 */
class VentasHuella extends Command
{
    protected $signature = 'ventas:huella';

    protected $description = 'Enseña qué creó y qué escribió la app en la base de Softland';

    public function handle(): int
    {
        $conn = DB::connection('softland');

        try {
            $conn->select('SELECT 1 AS x');
        } catch (Throwable $e) {
            $this->error('No se pudo conectar: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Base: <options=bold>'.$conn->getDatabaseName().'</>');
        $this->newLine();

        $ok = $this->loNuestro($conn);
        $ok = $this->migraciones($conn) && $ok;
        $ok = $this->enlaces($conn) && $ok;
        $this->enTablasDeSoftland($conn);
        $this->comoSeQuita();

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * El esquema `ventas`, tal como está en la base.
     *
     * Se lee de `sys.objects` y no de una lista escrita aquí: una lista a mano
     * se queda corta en la primera migración que alguien añada, y entonces este
     * comando miente justo en lo que vino a contestar.
     */
    private function loNuestro($conn): bool
    {
        $this->line('<options=bold>1 · Lo que la app creó: el esquema `ventas`</>');

        $objetos = $conn->select(
            "SELECT o.name, o.type, o.create_date,
                    (SELECT COUNT(*) FROM sys.columns c WHERE c.object_id = o.object_id) AS cols
             FROM sys.objects o
             JOIN sys.schemas s ON s.schema_id = o.schema_id
             WHERE s.name = 'ventas' AND o.type IN ('U', 'V')
             ORDER BY o.type, o.name"
        );

        if ($objetos === []) {
            $this->error('   No existe el esquema `ventas`. ¿Falta correr ventas:install?');

            return false;
        }

        $tablas = 0;
        $vistas = 0;

        foreach ($objetos as $o) {
            $esVista = trim($o->type) === 'V';
            $esVista ? $vistas++ : $tablas++;

            try {
                $filas = number_format($conn->table('ventas.'.$o->name)->count(), 0, ',', '.');
            } catch (Throwable) {
                $filas = 'ilegible';
            }

            $this->line(sprintf(
                '   %-26s %-5s %3d col %10s filas',
                'ventas.'.$o->name,
                $esVista ? 'vista' : 'tabla',
                $o->cols,
                $filas
            ));
        }

        $this->line(sprintf(
            '   <fg=green>%d tablas y %d %s.</> Nada de esto existía antes de instalar.',
            $tablas, $vistas, $vistas === 1 ? 'vista' : 'vistas'
        ));
        $this->newLine();

        return true;
    }

    /** Que estén todas. Una migración a medias es una columna que falta el día que se usa. */
    private function migraciones($conn): bool
    {
        $this->line('<options=bold>2 · Migraciones</>');

        $enDisco = [];

        foreach (File::files(database_path('migrations')) as $f) {
            $enDisco[] = $f->getFilenameWithoutExtension();
        }

        try {
            $aplicadas = $conn->table('ventas.migrations')->pluck('migration')->all();
        } catch (Throwable) {
            $this->error('   No se pudo leer ventas.migrations.');

            return false;
        }

        $faltan = array_diff($enDisco, $aplicadas);

        $this->line(sprintf('   aplicadas: %d de %d', count($aplicadas), count($enDisco)));

        if ($faltan !== []) {
            foreach ($faltan as $m) {
                $this->line('   <fg=red>falta:</> '.$m);
            }

            $this->line('   <fg=yellow>Se ponen al día con:</> php artisan ventas:install');
            $this->newLine();

            return false;
        }

        $this->line('   <fg=green>Al día.</>');
        $this->newLine();

        return true;
    }

    /**
     * Claves foráneas que crucen entre los dos esquemas.
     *
     * Tienen que ser cero, y es a propósito: `ventas.giro_sii` apunta a
     * `softland.cwtgiro` por su código y sin restricción declarada. Con una
     * clave foránea cruzada, quitar la app dejaría de ser borrar un esquema y
     * pasaría a ser una conversación con el ERP.
     */
    private function enlaces($conn): bool
    {
        $this->line('<options=bold>3 · Enlaces con el esquema `softland`</>');

        $fks = $conn->select(
            "SELECT fk.name,
                    sp.name AS esq_origen, tp.name AS tabla_origen,
                    sr.name AS esq_destino, tr.name AS tabla_destino
             FROM sys.foreign_keys fk
             JOIN sys.tables tp  ON tp.object_id = fk.parent_object_id
             JOIN sys.schemas sp ON sp.schema_id = tp.schema_id
             JOIN sys.tables tr  ON tr.object_id = fk.referenced_object_id
             JOIN sys.schemas sr ON sr.schema_id = tr.schema_id
             WHERE (sp.name = 'ventas' AND sr.name <> 'ventas')
                OR (sp.name <> 'ventas' AND sr.name = 'ventas')"
        );

        if ($fks === []) {
            $this->line('   <fg=green>Ninguna clave foránea cruza entre `ventas` y `softland`.</>');
        } else {
            foreach ($fks as $fk) {
                $this->line(sprintf(
                    '   <fg=red>%s</>  %s.%s → %s.%s',
                    $fk->name, $fk->esq_origen, $fk->tabla_origen, $fk->esq_destino, $fk->tabla_destino
                ));
            }
        }

        // La vista sí lee tablas del ERP; leer no es tocar, pero conviene
        // nombrarlo antes de que alguien lo descubra en el plan de ejecución.
        $this->line('   `ventas.nv_atributo_valor` lee `softland.nw_nventa` y sus tablas de atributos.');
        $this->line('   Lee: no escribe, no bloquea y no declara restricciones.');
        $this->newLine();

        return $fks === [];
    }

    /**
     * Lo otro que hay que decir: la app escribe **filas** en tablas del ERP.
     *
     * No es una modificación de estructura, pero es lo que de verdad cambia la
     * base del cliente, y quien administra esa base merece el número.
     */
    private function enTablasDeSoftland($conn): void
    {
        $this->line('<options=bold>4 · Filas escritas en tablas de Softland</>');

        // El mapa de idempotencia es la cuenta buena. Filtrar por
        // `Proceso = 'Venta Softland'` sólo vale para el documento de venta:
        // la cotización y la nota de venta no estampan esa columna.
        try {
            $docs = $conn->table('ventas.documento_app')
                ->selectRaw('tipo, COUNT(*) AS n')
                ->groupBy('tipo')
                ->orderBy('tipo')
                ->get();

            if ($docs->isEmpty()) {
                $this->line('   Documentos creados por la app: <options=bold>ninguno todavía</>');
            } else {
                foreach ($docs as $d) {
                    $this->line(sprintf('   %-16s %6d  (ventas.documento_app)', $d->tipo, $d->n));
                }
            }
        } catch (Throwable) {
            $this->line('   <fg=yellow>No se pudo leer ventas.documento_app.</>');
        }

        try {
            $n = $conn->table('softland.iw_gsaen')->where('Proceso', Facturacion::PROCESO)->count();
            $this->line(sprintf('   %-16s %6d  (softland.iw_gsaen, Proceso = «%s»)', 'facturas', $n, Facturacion::PROCESO));
        } catch (Throwable) {
            $this->line('   <fg=yellow>No se pudo contar en softland.iw_gsaen.</>');
        }

        // El catálogo de giros es la única escritura de la app en un maestro
        // del ERP fuera del flujo de venta, y hay que decir cuánta.
        try {
            $existentes = [];

            foreach ($conn->table('softland.cwtgiro')->pluck('GirCod') as $c) {
                $existentes[trim((string) $c)] = true;
            }

            $catalogo = Traduccion::catalogo();
            $puestos = 0;

            foreach ($catalogo as $fila) {
                if (isset($existentes[$fila['codigo']])) {
                    $puestos++;
                }
            }

            $this->line(sprintf(
                '   %-16s %6d de %d actecos vigentes presentes, sobre %s filas en el maestro',
                'giros',
                $puestos,
                count($catalogo),
                number_format(count($existentes), 0, ',', '.')
            ));
            $this->line('   Los pone `ventas:carga-giros --escribir`, y no pisa ninguna fila que ya exista.');
        } catch (Throwable) {
            $this->line('   <fg=yellow>No se pudo contar en softland.cwtgiro.</>');
        }

        $this->newLine();
    }

    private function comoSeQuita(): void
    {
        $this->line('<options=bold>5 · Si hubiera que quitarlo</>');
        $this->line('   Se borra el esquema `ventas` entero y la base queda como estaba.');
        $this->line('   Los documentos que la app haya escrito en las tablas del ERP se quedan:');
        $this->line('   son documentos de la empresa, indistinguibles de los del Softland de escritorio,');
        $this->line('   y se anulan o se borran desde el ERP como cualquier otro.');
        $this->newLine();
    }
}
