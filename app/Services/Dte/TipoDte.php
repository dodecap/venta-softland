<?php

namespace App\Services\Dte;

/**
 * Los tipos de documento electrónico, y cómo se llaman en cada mundo.
 *
 * Tres nombres distintos para la misma cosa, y hay que traducir entre ellos:
 *
 *   - el **código del SII** (33, 34, 39, 41, 61), que va en el XML y en el CAF;
 *   - la pareja **`Tipo` + `SubTipoDocto`** de Softland, que es la clave con la
 *     que `iw_gsaen` guarda el documento;
 *   - el nombre que usa la app.
 *
 * El mapa no está donde uno lo buscaría. `cwttdoc.DTEDocSII` trae el código
 * del SII para los documentos de **compra** y lo deja **vacío** para los de
 * venta, que son justo los que emitimos. El mapa bueno vive en
 * `dte_siitdoc`, indexado por `(Tipo, SubTipoDocto)`. Esta clase es esa tabla
 * escrita a mano, para no consultarla en cada línea.
 *
 * ## Por qué la boleta va aparte
 *
 * La factura y la nota de crédito se envían por SOAP a `palena.sii.cl`. La
 * boleta **no**: va por una API REST distinta (`api.sii.cl/recursos/v1`), y
 * además obliga a un reporte diario de consumo de folios que la factura no
 * pide. Son dos integraciones, no una, y conviene que el tipo lo diga solo
 * antes de que alguien mande una boleta por el tubo equivocado.
 *
 * ## Lo que hoy se puede emitir
 *
 * INNOVAGES tiene folios CAF para 33 y 61, y ninguno para 39 ni 41. El tipo
 * existe igual, declarado y mapeado: el día que lleguen los folios, lo que
 * falta es el transporte REST, no volver a pensar esto.
 */
enum TipoDte: int
{
    case FACTURA = 33;
    case FACTURA_EXENTA = 34;
    case BOLETA = 39;
    case BOLETA_EXENTA = 41;
    case NOTA_CREDITO = 61;

    /** Cómo lo nombra el SII. */
    public function nombre(): string
    {
        return match ($this) {
            self::FACTURA => 'Factura electrónica',
            self::FACTURA_EXENTA => 'Factura exenta electrónica',
            self::BOLETA => 'Boleta afecta electrónica',
            self::BOLETA_EXENTA => 'Boleta exenta electrónica',
            self::NOTA_CREDITO => 'Nota de crédito electrónica',
        };
    }

    /**
     * Cómo se nombra un código del SII, venga de donde venga.
     *
     * Existe para el papel: en una referencia el tipo llega como número —«33»,
     * «61»— y escribirlo así en un documento que lee el cliente no dice nada.
     * Los que no son nuestros —la guía, la orden de compra— también aparecen en
     * las referencias, así que se nombran igual.
     */
    public static function nombreSii(int $codigo): string
    {
        return self::tryFrom($codigo)?->nombre() ?? match ($codigo) {
            52 => 'Guía de despacho electrónica',
            56 => 'Nota de débito electrónica',
            801 => 'Orden de compra',
            802 => 'Nota de pedido',
            default => 'Documento '.$codigo,
        };
    }

    /**
     * La pareja con la que Softland lo guarda en `iw_gsaen`: `Tipo` y
     * `SubTipoDocto`. Sale de `dte_siitdoc`.
     *
     * @return array{0: string, 1: string}
     */
    public function claveSoftland(): array
    {
        return match ($this) {
            self::FACTURA => ['F', 'T'],
            self::FACTURA_EXENTA => ['F', 'S'],
            self::BOLETA => ['B', 'T'],
            self::BOLETA_EXENTA => ['B', 'S'],
            self::NOTA_CREDITO => ['N', 'T'],
        };
    }

    /** Desde la pareja de Softland. Devuelve null si ese par no es electrónico. */
    public static function desdeSoftland(?string $tipo, ?string $subTipo): ?self
    {
        $clave = strtoupper(trim((string) $tipo)).strtoupper(trim((string) $subTipo));

        return match ($clave) {
            'FT' => self::FACTURA,
            'FS' => self::FACTURA_EXENTA,
            'BT' => self::BOLETA,
            'BS' => self::BOLETA_EXENTA,
            'NT' => self::NOTA_CREDITO,
            default => null,
        };
    }

    /**
     * Si viaja por la API REST de boletas en vez del SOAP de siempre.
     *
     * No es un detalle de transporte: cambia la autenticación, el formato del
     * sobre y la forma de preguntar por el estado.
     */
    public function porApiRest(): bool
    {
        return in_array($this, [self::BOLETA, self::BOLETA_EXENTA], true);
    }

    /** Si el SII pide reporte diario de consumo de folios (RCOF). Solo la boleta. */
    public function exigeConsumoFolios(): bool
    {
        return $this->porApiRest();
    }

    /**
     * Si lleva IVA.
     *
     * Ojo con la boleta afecta: **lleva** IVA, pero no lo desglosa en el XML —
     * el `<Totales>` va con `MntTotal` bruto y nada más. Que sea afecta y que
     * se muestre desglosado son dos cosas distintas.
     */
    public function afecto(): bool
    {
        return in_array($this, [self::FACTURA, self::BOLETA, self::NOTA_CREDITO], true);
    }

    /** Si el monto del documento se escribe bruto, sin separar neto e IVA. */
    public function montoBruto(): bool
    {
        return $this->porApiRest();
    }

    /**
     * El receptor cuando la venta no identifica cliente.
     *
     * Solo la boleta lo admite: el SII acepta `66666666-6` como consumidor
     * final. Una factura sin receptor no existe.
     */
    public function receptorAnonimo(): ?string
    {
        return $this->porApiRest() ? '66666666-6' : null;
    }

    /**
     * La razón social que acompaña a ese receptor.
     *
     * Va sin tilde —«Generico»— porque así la escribe Softland y así aparece en
     * los ejemplos del SII. Una tilde de más cambia los bytes y, con ellos, el
     * timbre. No es un descuido de ortografía: es el valor literal.
     */
    public function razonSocialAnonima(): ?string
    {
        return $this->porApiRest() ? 'Cliente Generico' : null;
    }
}
