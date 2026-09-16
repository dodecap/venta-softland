<?php

namespace App\Services\Documentos;

/**
 * Qué documento es. No una plantilla: un dato.
 *
 * Siete tipos comerciales comparten el noventa por ciento del papel — logo,
 * cliente, detalle, totales, pie — y se distinguen en dos o tres bloques.
 * Duplicar la plantilla siete veces es garantizar que un día la dirección de la
 * empresa salga distinta en la factura y en la guía.
 *
 * Es la misma decisión que ya funcionó con los 21 maestros de la fase 2:
 * agregar un maestro es agregar un arreglo, no un controlador. Agregar un
 * documento es agregar un `case`.
 *
 * ## Los que todavía no se dibujan
 *
 * Factura, boleta, guía y nota de crédito están declarados y `emiteDte()` dice
 * que sí. Sus bloques propios — el timbre PDF417, la leyenda de la resolución —
 * no están escritos, y es a propósito: el formato del PDF de un DTE lo fija el
 * SII, y el timbre sale del XML firmado. Ese PDF se dibuja **a partir del DTE
 * ya emitido**, nunca al revés. Construirlo antes de tener un CAF real sería
 * dibujar algo que después hay que rehacer.
 */
enum TipoDocumento: string
{
    case COTIZACION = 'cotizacion';
    case NOTA_VENTA = 'nota_venta';
    case FACTURA = 'factura';
    case BOLETA = 'boleta';
    case GUIA_DESPACHO = 'guia_despacho';
    case NOTA_CREDITO = 'nota_credito';
    case ORDEN_SERVICIO = 'orden_servicio';
    case COMPROBANTE_COBRANZA = 'comprobante_cobranza';
    /*
     * La otra cara de la nota de venta en el ciclo de distribuidor.
     *
     * Mismo documento, mismo número y las mismas líneas, pero dirigido al
     * **proveedor** en vez de al cliente: INNOVAGES le pide a Softland Santiago
     * lo que su cliente le compró a él. Por eso no es un formato alternativo de
     * la nota de venta sino un tipo documental propio — cambia el destinatario,
     * cambia el título y cambian las columnas.
     */
    case ORDEN_COMPRA = 'orden_compra';

    /** Cómo se titula en el papel. */
    public function titulo(): string
    {
        return match ($this) {
            self::COTIZACION => 'Cotización',
            self::NOTA_VENTA => 'Nota de venta',
            self::FACTURA => 'Factura electrónica',
            self::BOLETA => 'Boleta electrónica',
            self::GUIA_DESPACHO => 'Guía de despacho',
            self::NOTA_CREDITO => 'Nota de crédito',
            self::ORDEN_SERVICIO => 'Orden de servicio',
            self::COMPROBANTE_COBRANZA => 'Comprobante de cobranza',
            self::ORDEN_COMPRA => 'Orden de compra',
        };
    }

    /** Nombre del archivo adjunto. Sin espacios: hay gestores de correo que los parten. */
    public function archivo(int $numero): string
    {
        $base = match ($this) {
            self::COTIZACION => 'cotizacion',
            self::NOTA_VENTA => 'nota-de-venta',
            self::FACTURA => 'factura',
            self::BOLETA => 'boleta',
            self::GUIA_DESPACHO => 'guia-de-despacho',
            self::NOTA_CREDITO => 'nota-de-credito',
            self::ORDEN_SERVICIO => 'orden-de-servicio',
            self::COMPROBANTE_COBRANZA => 'comprobante-de-cobranza',
            self::ORDEN_COMPRA => 'orden-de-compra',
        };

        return $base.'-'.$numero.'.pdf';
    }

    /**
     * Qué bloques lleva, en orden. El motor no sabe de tipos: recorre esto.
     *
     * Un bloque que no está en la lista no se dibuja aunque venga el dato, y un
     * bloque sin dato no ocupa espacio aunque esté en la lista.
     */
    public function bloques(): array
    {
        return match ($this) {
            /*
             * El papel que la empresa lleva años entregando.
             *
             * Cada uno tiene su propia cabecera y su propio pie porque así son:
             * la cotización abre con los dos logos y un párrafo, la nota de
             * venta con el nombre de la empresa y el número en un recuadro, y la
             * orden de compra con el distribuidor arriba a la izquierda. No es
             * la misma hoja con adornos distintos.
             */
            self::COTIZACION => [
                'cabecera_cotizacion', 'intro_cotizacion', 'receptor_comercial',
                'detalle_uf', 'condiciones_totales', 'cierre_cotizacion', 'pie_comercial',
            ],
            self::NOTA_VENTA => [
                'cabecera_nota_venta', 'receptor_nota_venta',
                'detalle_nota_venta', 'condiciones_totales', 'pie_comercial',
            ],
            self::ORDEN_COMPRA => [
                'cabecera_orden_compra', 'receptor_orden_compra',
                'detalle_orden_compra', 'facturar_a', 'pie_orden_compra',
            ],
            self::ORDEN_SERVICIO, self::COMPROBANTE_COBRANZA => [
                'cliente', 'detalle', 'totales', 'notas', 'vendedor',
            ],
            // Los legales llevan su propia lista y su propia hoja: lo que se
            // dibuja aquí es la **representación impresa** de un DTE, y su
            // forma no la elegimos nosotros. Sigue siendo una lista de bloques,
            // así que la boleta no copia plantilla: repite bloques.
            self::FACTURA, self::BOLETA, self::NOTA_CREDITO => [
                'receptor', 'referencias', 'detalle_dte', 'cierre_dte', 'acuse',
            ],
            self::GUIA_DESPACHO => [
                'cliente', 'detalle', 'despacho', 'transporte',
            ],
        };
    }

    /**
     * Otros papeles con los que puede salir este mismo documento.
     *
     * La nota de venta tiene dos caras: la que se le entrega al cliente y la
     * orden de compra que se le manda al proveedor, con los mismos datos
     * mirados desde el otro lado. No es un formato alternativo — es otro
     * documento, con otro destinatario, y por eso se versiona aparte.
     *
     * @return list<self>
     */
    public function papelesAlternativos(): array
    {
        return match ($this) {
            self::NOTA_VENTA => [self::ORDEN_COMPRA],
            default => [],
        };
    }

    /**
     * Sobre qué hoja se dibuja.
     *
     * Los comerciales van en `base`, la hoja de la casa. Los legales van en
     * `dte`, que reproduce la representación impresa que el SII y la costumbre
     * dan por buena — recuadro rojo con el folio, timbre abajo a la izquierda,
     * acuse de recibo al pie— y que es la que el cliente reconoce, porque es la
     * que le llega desde hace años.
     */
    public function plantilla(): string
    {
        return match (true) {
            $this->emiteDte() => 'dte',
            // Los tres papeles del negocio, con la identidad de la empresa.
            in_array($this, [self::COTIZACION, self::NOTA_VENTA, self::ORDEN_COMPRA], true) => 'empresa',
            default => 'base',
        };
    }

    /**
     * El tamaño del papel.
     *
     * Carta en los legales, y no es un capricho: es el tamaño en que Softland
     * los viene imprimiendo, y el papel que hay en la impresora de la oficina.
     */
    public function papel(): string
    {
        return $this->emiteDte() ? 'letter' : 'a4';
    }

    /** El código del SII, que es como se nombra el documento en el papel. */
    public function codigoSii(): ?int
    {
        return match ($this) {
            self::FACTURA => 33,
            self::BOLETA => 39,
            self::GUIA_DESPACHO => 52,
            self::NOTA_CREDITO => 61,
            default => null,
        };
    }

    /**
     * ¿El documento legal es un DTE?
     *
     * Importa para no confundirse: en los que devuelven `true`, lo que emite
     * este motor es la **representación impresa**, no el documento. El legal es
     * el XML firmado y aceptado por el SII.
     */
    public function emiteDte(): bool
    {
        return match ($this) {
            self::FACTURA, self::BOLETA, self::GUIA_DESPACHO, self::NOTA_CREDITO => true,
            default => false,
        };
    }

    /**
     * Los estados que Softland admite en este documento, y cómo se leen.
     *
     * Es el **vocabulario entero**: en la cotización no hay más que estos
     * cuatro, y en la nota de venta tampoco. Importa no inventarse ninguno,
     * porque el código de estado no es decorativo — es lo que decide si el
     * documento aparece en las búsquedas del Softland de escritorio.
     *
     * Ojo con `N`: **es «nula», no «nueva»**. Un documento que nace en `N` nace
     * anulado. Los tres primeros meses de este proyecto se escribió así, y por
     * eso ni la cotización ni la nota de venta se veían en el ERP.
     *
     * Su gemelo en el teléfono, que además les pone color, está en
     * `mobile/src/documentos.js`. Los dos tienen que decir lo mismo.
     */
    public function estados(): array
    {
        return match ($this) {
            self::COTIZACION => [
                'P' => 'Pendiente',
                'V' => 'En nota de venta',
                'N' => 'Nula',
                'R' => 'Perdida',
            ],
            self::NOTA_VENTA, self::ORDEN_COMPRA => [
                'P' => 'Pendiente',
                'A' => 'Aprobada',
                'N' => 'Nula',
                'C' => 'Concluida',
            ],
            default => [],
        };
    }

    /** El estado en palabras. Un código que no esté en la tabla se muestra tal cual. */
    public function estado(?string $codigo): string
    {
        $c = strtoupper(trim((string) $codigo));

        return $this->estados()[$c] ?? $c;
    }

    /**
     * Cómo lo nombran el maestro y la app (`$this->recurso()` de los controladores).
     *
     * La orden de compra dice `nota_venta` porque **es** una nota de venta
     * mirada desde el otro lado: el mismo número, las mismas líneas y el mismo
     * total, dirigidos al proveedor en vez de al cliente. No tiene tablas
     * propias ni correlativo propio.
     */
    public function recurso(): string
    {
        return match ($this) {
            self::COTIZACION => 'cotizacion',
            self::NOTA_VENTA, self::ORDEN_COMPRA => 'nota_venta',
            default => $this->value,
        };
    }

    /** De un recurso de la app al tipo. `notas_venta` también, que así lo nombra el maestro. */
    public static function desdeRecurso(string $recurso): self
    {
        return match ($recurso) {
            'cotizacion', 'cotizaciones' => self::COTIZACION,
            'nota_venta', 'notas_venta' => self::NOTA_VENTA,
            default => self::from($recurso),
        };
    }
}
