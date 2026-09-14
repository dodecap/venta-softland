<?php

namespace App\Services\Notificaciones;

/**
 * Catálogo de eventos notificables del flujo de ventas.
 *
 * Están declarados TODOS los del flujo completo desde ahora: así el admin ve la
 * lista entera en la app y puede dejar configurados los destinatarios antes de
 * que la fase correspondiente exista. Los que aún no se disparan quedan
 * marcados con `fase` > 1.
 */
class Eventos
{
    public const USUARIO_HABILITADO = 'usuario_habilitado';

    public const COTIZACION_ENVIADA = 'cotizacion_enviada';

    public const COTIZACION_ACEPTADA = 'cotizacion_aceptada';

    public const COTIZACION_PERDIDA = 'cotizacion_perdida';

    public const NV_CREADA = 'nv_creada';

    public const NV_ENVIADA = 'nv_enviada';

    public const NV_REQUIERE_APROBACION = 'nv_requiere_aprobacion';

    public const NV_APROBADA = 'nv_aprobada';

    public const NV_RECHAZADA = 'nv_rechazada';

    public const FACTURA_EMITIDA = 'factura_emitida';

    public const BOLETA_EMITIDA = 'boleta_emitida';

    public const NC_EMITIDA = 'nc_emitida';

    public const DTE_RECHAZADO_SII = 'dte_rechazado_sii';

    /**
     * Definición de cada evento: etiqueta para la app, a quién avisa por defecto
     * y en qué fase del proyecto empieza a dispararse.
     *
     * @return array<string, array{label: string, descripcion: string, fase: int, defaults: array<string, bool|string|null>}>
     */
    public static function catalogo(): array
    {
        return [
            self::USUARIO_HABILITADO => [
                'label' => 'Usuario habilitado',
                'descripcion' => 'El administrador habilita a un vendedor: se le avisa que ya puede entrar.',
                'fase' => 1,
                'defaults' => ['avisar_dueno' => true, 'avisar_jefe' => false, 'avisar_cliente' => false, 'roles' => null],
            ],
            self::COTIZACION_ENVIADA => [
                'label' => 'Cotización enviada',
                'descripcion' => 'El vendedor envía la cotización al cliente.',
                'fase' => 2,
                'defaults' => ['avisar_dueno' => true, 'avisar_jefe' => false, 'avisar_cliente' => true, 'roles' => null],
            ],
            self::COTIZACION_ACEPTADA => [
                'label' => 'Cotización aceptada',
                'descripcion' => 'La cotización pasa a nota de venta.',
                'fase' => 2,
                'defaults' => ['avisar_dueno' => true, 'avisar_jefe' => true, 'avisar_cliente' => false, 'roles' => null],
            ],
            self::COTIZACION_PERDIDA => [
                'label' => 'Cotización perdida',
                'descripcion' => 'La cotización se marca perdida con su motivo (softland.nwperdida).',
                'fase' => 2,
                'defaults' => ['avisar_dueno' => false, 'avisar_jefe' => true, 'avisar_cliente' => false, 'roles' => null],
            ],
            self::NV_CREADA => [
                'label' => 'Nota de venta creada',
                'descripcion' => 'Se generó una nota de venta en Softland.',
                'fase' => 3,
                'defaults' => ['avisar_dueno' => true, 'avisar_jefe' => false, 'avisar_cliente' => false, 'roles' => 'facturacion'],
            ],
            self::NV_ENVIADA => [
                'label' => 'Nota de venta enviada',
                'descripcion' => 'El vendedor le manda la nota de venta al cliente, con el PDF adjunto.',
                'fase' => 3,
                'defaults' => ['avisar_dueno' => true, 'avisar_jefe' => false, 'avisar_cliente' => true, 'roles' => null],
            ],
            self::NV_REQUIERE_APROBACION => [
                'label' => 'Nota de venta requiere aprobación',
                'descripcion' => 'La NV pasó el tope de descuento o de monto del vendedor.',
                'fase' => 3,
                'defaults' => ['avisar_dueno' => false, 'avisar_jefe' => true, 'avisar_cliente' => false, 'roles' => null],
            ],
            self::NV_APROBADA => [
                'label' => 'Nota de venta aprobada',
                'descripcion' => 'El jefe aprobó la nota de venta.',
                'fase' => 3,
                'defaults' => ['avisar_dueno' => true, 'avisar_jefe' => false, 'avisar_cliente' => false, 'roles' => 'facturacion'],
            ],
            self::NV_RECHAZADA => [
                'label' => 'Nota de venta rechazada',
                'descripcion' => 'El jefe rechazó la nota de venta, con motivo.',
                'fase' => 3,
                'defaults' => ['avisar_dueno' => true, 'avisar_jefe' => false, 'avisar_cliente' => false, 'roles' => null],
            ],
            self::FACTURA_EMITIDA => [
                'label' => 'Factura electrónica emitida',
                'descripcion' => 'Se emitió la factura (DTE 33) y quedó aceptada por el SII.',
                'fase' => 4,
                'defaults' => ['avisar_dueno' => true, 'avisar_jefe' => false, 'avisar_cliente' => true, 'roles' => null],
            ],
            self::BOLETA_EMITIDA => [
                'label' => 'Boleta electrónica emitida',
                'descripcion' => 'Se emitió la boleta (DTE 39).',
                'fase' => 4,
                'defaults' => ['avisar_dueno' => true, 'avisar_jefe' => false, 'avisar_cliente' => false, 'roles' => null],
            ],
            self::NC_EMITIDA => [
                'label' => 'Nota de crédito emitida',
                'descripcion' => 'Se emitió una NC (DTE 61) sobre un documento anterior.',
                'fase' => 4,
                'defaults' => ['avisar_dueno' => true, 'avisar_jefe' => true, 'avisar_cliente' => true, 'roles' => null],
            ],
            self::DTE_RECHAZADO_SII => [
                'label' => 'DTE rechazado por el SII',
                'descripcion' => 'El SII rechazó el documento: hay que corregirlo.',
                'fase' => 4,
                'defaults' => ['avisar_dueno' => true, 'avisar_jefe' => true, 'avisar_cliente' => false, 'roles' => 'facturacion,admin'],
            ],
        ];
    }

    /** @return string[] */
    public static function todos(): array
    {
        return array_keys(self::catalogo());
    }

    public static function existe(string $evento): bool
    {
        return array_key_exists($evento, self::catalogo());
    }

    /**
     * Familia a la que pertenece el evento, para agrupar el buzón del vendedor.
     *
     * Vive aquí y no en la app: si mañana se agrega un evento, la pestaña en
     * que cae se decide en el mismo archivo donde se declara, no en el teléfono.
     */
    public static function familia(string $evento): string
    {
        return match (true) {
            str_starts_with($evento, 'cotizacion_') => 'cotizacion',
            str_starts_with($evento, 'nv_') => 'nota_venta',
            str_starts_with($evento, 'factura_'),
            str_starts_with($evento, 'boleta_'),
            str_starts_with($evento, 'nc_'),
            str_starts_with($evento, 'dte_') => 'documento',
            default => 'sistema',
        };
    }

    /**
     * Pestañas del buzón, en orden. La clave viaja en cada aviso como `familia`.
     *
     * @return array<string, string>
     */
    public static function familias(): array
    {
        return [
            'cotizacion' => 'Cotizaciones',
            'nota_venta' => 'Notas de venta',
            'documento' => 'Documentos',
            'sistema' => 'Sistema',
        ];
    }

    public static function label(string $evento): string
    {
        return self::catalogo()[$evento]['label'] ?? $evento;
    }
}
