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
            self::COTIZACION => [
                'cliente', 'detalle', 'totales', 'condiciones', 'notas', 'vendedor',
            ],
            self::NOTA_VENTA => [
                'cliente', 'detalle', 'totales', 'condiciones', 'despacho', 'notas', 'vendedor',
            ],
            self::ORDEN_SERVICIO, self::COMPROBANTE_COBRANZA => [
                'cliente', 'detalle', 'totales', 'notas', 'vendedor',
            ],
            // Los legales: el bloque `timbre` y `referencias` llegan con la fase 4.
            self::FACTURA, self::BOLETA, self::NOTA_CREDITO => [
                'cliente', 'detalle', 'totales', 'condiciones', 'referencias',
            ],
            self::GUIA_DESPACHO => [
                'cliente', 'detalle', 'despacho', 'transporte',
            ],
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

    /** Cómo lo nombran el maestro y la app (`$this->recurso()` de los controladores). */
    public function recurso(): string
    {
        return match ($this) {
            self::COTIZACION => 'cotizacion',
            self::NOTA_VENTA => 'nota_venta',
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
