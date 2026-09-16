<?php

namespace App\Services\Dte;

use Illuminate\Support\Facades\DB;

/**
 * Lo que la empresa decide sobre cómo factura, y que no está en Softland.
 *
 * Hoy es una sola decisión, y es la que separa el ciclo normal del ciclo de
 * distribuidor.
 *
 * ## A quién se le factura una nota de venta
 *
 * Lo normal —y lo que la app viene a resolver— es que el cliente de la
 * cotización, el de la nota de venta y el de la factura sean **el mismo RUT**.
 * Por eso la llave nace apagada: con ella apagada, el receptor se hereda de la
 * nota de venta, el campo ni se enseña y no hay forma de equivocarse.
 *
 * INNOVAGES hace otra cosa: es distribuidor de Softland, cotiza al cliente final
 * y le hace la nota de venta, pero **quien le factura al cliente es Softland
 * Santiago**, que es otro RUT. INNOVAGES emite aparte una factura de comisión
 * contra Softland y la cuelga de esa nota de venta. De 192 facturas enlazadas a
 * una nota de venta, **190 van a un cliente distinto** —188 a Softland
 * Ingeniería—.
 *
 * Eso no es un error que haya que impedir: es su negocio, y hay que seguir
 * sirviéndolo. Pero tampoco puede ser lo que pasa por omisión, porque en una
 * instalación normal facturarle a otro RUT es un documento mal emitido y la
 * corrección es una nota de crédito.
 *
 * De ahí la llave: encendida, quien factura puede cambiar el receptor y la nota
 * de venta entra como **referencia y sugerencia**, no como fuente obligatoria.
 *
 * ## Dónde se guarda
 *
 * En `ventas.config`, que es donde vive lo que configura el administrador desde
 * la app. Nunca un `if empresa == INNOVAGES` en ninguna parte: la app está hecha
 * para replicarse a otra empresa Softland cambiando configuración, no código.
 */
class ReglasFactura
{
    public const CLAVE = 'facturacion';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * @param  bool|null  $forzado  ignora la configuración y responde esto. Sólo
     *                              para reproducir documentos históricos: los de
     *                              INNOVAGES son comisiones, y con la llave
     *                              apagada —como nace— no se podrían reescribir.
     */
    public function __construct(private readonly ?bool $forzado = null) {}

    /**
     * Si quien factura puede cambiarle el receptor a una factura que nace de
     * una nota de venta.
     */
    public function receptorEditable(): bool
    {
        return $this->forzado ?? (bool) ($this->valores()['receptor_editable'] ?? false);
    }

    public function fijarReceptorEditable(bool $editable): void
    {
        $this->guardar(['receptor_editable' => $editable]);
    }

    /** @return array<string, mixed> */
    public function valores(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $json = DB::connection('softland')->table('ventas.config')
            ->where('clave', self::CLAVE)->value('valor');

        $datos = $json ? json_decode($json, true) : [];

        return self::$cache = is_array($datos) ? $datos : [];
    }

    /** @param  array<string, mixed>  $datos */
    public function guardar(array $datos): void
    {
        DB::connection('softland')->table('ventas.config')->updateOrInsert(
            ['clave' => self::CLAVE],
            ['valor' => json_encode($datos + $this->valores(), JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
        );

        self::$cache = null;
    }

    /** Para las pruebas y para los comandos que escriben y deshacen. */
    public static function olvidar(): void
    {
        self::$cache = null;
    }
}
