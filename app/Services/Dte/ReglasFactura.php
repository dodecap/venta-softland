<?php

namespace App\Services\Dte;

use App\Services\Softland\Permisos;
use Illuminate\Support\Facades\DB;

/**
 * Lo que la empresa decide sobre cómo factura, y que no está en Softland.
 *
 * Cuatro decisiones: a quién se le factura una nota de venta —que separa el
 * ciclo normal del de distribuidor—, si el documento sale hacia el SII solo o
 * espera a que alguien lo mande, y qué papeles nombra en sus referencias: la
 * orden de compra del cliente y el número de la nota de venta.
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
 * ## Y por qué no basta con la llave
 *
 * Porque Softland ya contesta esta misma pregunta, **por usuario**, y mejor:
 * `IW · Iw_FacLin · NVOtroAuxiliar`, «Permite que la Factura quede asociada a
 * una Nota de Venta de otro Cliente». En INNOVAGES lo trae el perfil `IW/001` y
 * no el `IW/vend`, que es justo lo que se quiere decir: los vendedores hacen el
 * ciclo normal y el de distribuidor lo hace quien administra.
 *
 * Así que son **dos condiciones y se cumplen las dos**: que el ERP se lo
 * conceda a ese usuario, y que la empresa no lo haya apagado aquí. La llave
 * nuestra sólo puede **apagar**, nunca encender lo que Softland negó — es la
 * misma línea que con `nwparam.CheckApruebaNv`: se obedece al ERP donde manda
 * sobre el documento, y se decide aquí lo que es comportamiento de esta app.
 *
 * Ojo con la diferencia de alcance, que es el cambio de fondo: la llave es de
 * empresa y el permiso es de persona. Dos vendedores de la misma empresa pueden
 * tener respuestas distintas, y eso está bien.
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
    public function __construct(
        private readonly ?bool $forzado = null,
        private readonly Permisos $permisos = new Permisos,
    ) {}

    /**
     * Si quien factura puede cambiarle el receptor a una factura que nace de
     * una nota de venta.
     *
     * @param  string|null  $usuario  el de Softland (`wisusuarios.Usuario`). Sin
     *                                él se contesta sólo por la empresa: es lo
     *                                que quiere saber la pantalla de
     *                                configuración, que pregunta «¿está
     *                                permitido aquí?», no «¿puedo yo?».
     */
    public function receptorEditable(?string $usuario = null): bool
    {
        if ($this->forzado !== null) {
            return $this->forzado;
        }

        if (! (bool) ($this->valores()['receptor_editable'] ?? false)) {
            return false;
        }

        return $usuario === null || $this->permisos->puede($usuario, Permisos::FACTURA_OTRO_CLIENTE);
    }

    /** Si la empresa lo permite, sin mirar a nadie en particular. */
    public function receptorEditableEnLaEmpresa(): bool
    {
        return $this->forzado ?? (bool) ($this->valores()['receptor_editable'] ?? false);
    }

    public function fijarReceptorEditable(bool $editable): void
    {
        $this->guardar(['receptor_editable' => $editable]);
    }

    /**
     * Si emitir manda el documento al SII en el mismo acto.
     *
     * ## Por qué es una llave nuestra y no la de Softland
     *
     * Softland tiene las suyas en `soempre` —`DTEFacturaLote`,
     * `DTEFacturaLinea`, `TipoEnvioNCredito`…— y la tentación de leerlas es
     * grande. No se hace, por tres razones:
     *
     *  - **describen cómo manda el ERP de escritorio, no cómo manda esta app**.
     *    Son dos programas emitiendo el mismo tipo de documento, y que la
     *    oficina revise su lote a fin de día no dice nada de lo que tiene que
     *    hacer el teléfono del vendedor en terreno;
     *  - **hoy dirían que no mande**. INNOVAGES tiene `DTEFacturaLote = 1` y
     *    `DTEFacturaLinea = 0`, y NETDOMAIN lo mismo. Leerlas al pie de la letra
     *    dejaría la app sin mandar nunca, que es justo lo contrario de lo que se
     *    pidió;
     *  - **su significado se deduce, no se sabe**. Para la factura son dos
     *    columnas booleanas y para los demás documentos una sola `TipoEnvio*`;
     *    nada dice cuál valor es cuál. Construir el comportamiento sobre una
     *    lectura no comprobada de una bandera del ERP es de lo que uno se
     *    arrepiente medio año después.
     *
     * La regla del proyecto sigue siendo la misma: se obedece al ERP donde el
     * ERP manda sobre **el documento** —como `nwparam.CheckApruebaNv` con el
     * estado de la nota de venta— y se decide aquí lo que es del
     * **comportamiento de esta app**. Esto es lo segundo: el documento que sale
     * es idéntico en los dos modos.
     *
     * Nace encendida: una factura escrita y sin mandar depende de que alguien se
     * acuerde, y así es como la del día 30 se emite el 2.
     */
    public function envioAutomatico(): bool
    {
        return (bool) ($this->valores()['envio_automatico'] ?? true);
    }

    public function fijarEnvioAutomatico(bool $automatico): void
    {
        $this->guardar(['envio_automatico' => $automatico]);
    }

    /**
     * Si la factura nombra en el DTE la **orden de compra del cliente**.
     *
     * Es la referencia 801 del SII, y es la que de verdad le sirve a quien
     * recibe la factura: la cuadra contra lo que encargó. Nace encendida y sólo
     * aparece cuando hay OC que poner, así que apagarla es raro; la llave
     * existe porque hay empresas que no trabajan con órdenes de compra y
     * prefieren no ver el renglón nunca.
     */
    public function referenciaOrdenCompra(): bool
    {
        return (bool) ($this->valores()['referencia_orden_compra'] ?? true);
    }

    public function fijarReferenciaOrdenCompra(bool $poner): void
    {
        $this->guardar(['referencia_orden_compra' => $poner]);
    }

    /**
     * Si la factura nombra en el DTE el **número de la nota de venta**.
     *
     * Es la referencia 802, «Nota de Pedido», y aquí sí hay que tener cuidado:
     * INNOVAGES la pone en 188 documentos, pero eso es una costumbre suya, no
     * una regla del SII. En una empresa donde la nota de venta es un papel
     * interno, publicar su número en un documento tributario que lee el cliente
     * no aporta nada.
     *
     * Nace encendida porque es lo que hace hoy el Softland de escritorio de
     * INNOVAGES, y cambiar en silencio lo que el ERP ya escribe sería la peor
     * forma de estrenar esto.
     */
    public function referenciaNotaVenta(): bool
    {
        return (bool) ($this->valores()['referencia_nota_venta'] ?? true);
    }

    public function fijarReferenciaNotaVenta(bool $poner): void
    {
        $this->guardar(['referencia_nota_venta' => $poner]);
    }

    /**
     * Lo que dice Softland de su propio envío, para enseñarlo al lado.
     *
     * No decide nada: está para que quien elige el modo vea qué hace el ERP y
     * no tenga que abrirlo para saberlo.
     */
    public function envioSegunSoftland(): array
    {
        $e = DB::connection('softland')->table('softland.soempre')
            ->first(['DTEFacturaLote', 'DTEFacturaLinea', 'TipoEnvioNCredito', 'TipoEnvioGDespacho']);

        return [
            'factura_lote' => (bool) ($e->DTEFacturaLote ?? false),
            'factura_linea' => (bool) ($e->DTEFacturaLinea ?? false),
            'nota_credito' => (int) ($e->TipoEnvioNCredito ?? 0),
            'guia_despacho' => (int) ($e->TipoEnvioGDespacho ?? 0),
        ];
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
