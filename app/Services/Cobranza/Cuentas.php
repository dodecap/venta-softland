<?php

namespace App\Services\Cobranza;

use Illuminate\Support\Facades\DB;

/**
 * De qué cuenta contable cuelga cada forma de pago, y cuál es la del cliente.
 *
 * **Casi nada de esto se pregunta**: ya está escrito en `iwparam`, que es el
 * parámetro del módulo de facturación y existe en cualquier instalación que
 * facture —que es requisito de esta app—. Medido en INNOVAGES, las dos cuentas
 * del comprobante real `2026-00009000` salen de ahí sin tocar nada:
 * `CtaCliente` = 1-01-03-001 al haber y `CtaPagoTf` = 1-01-02-003 al debe.
 *
 * Lo que `iwparam` deja vacío —hoy la tarjeta de crédito y las pasarelas— se
 * completa en `ventas.config`, clave `cobranza`. La regla es la misma de la
 * identidad heredando de `soempre`: **el ERP manda y nosotros sólo rellenamos
 * el hueco**. Y no se escribe en `iwparam`: de las tablas de Softland esta app
 * escribe una columna, `CodBarra`, y ninguna más. Guardar nuestra
 * configuración dentro del ERP serían dos verdades esperando a diferenciarse.
 *
 * Tampoco baja al teléfono. El teléfono manda «forma de pago = transferencia»
 * y el servidor resuelve la cuenta — la misma regla que «el teléfono no decide
 * impuestos». Una cuenta contable dentro de un APK que se descompila no pinta
 * nada.
 */
class Cuentas
{
    public const CLAVE = 'cobranza';

    /**
     * Los medios de pago que esta app entiende.
     *
     * `[rótulo, columna de iwparam, TtdCod por omisión]`. El `TtdCod` sale de
     * `cwttdoc`, que lo declara cada empresa: la app **ofrece** el suyo por
     * omisión y no inventa códigos — si la empresa no declara ese tipo, se
     * elige otro en la configuración y aquí no se decide nada.
     */
    public const MEDIOS = [
        'efectivo' => ['Efectivo', 'CtaPagoEfec', 'EF'],
        'cheque_dia' => ['Cheque al día', 'CtaPagoChDia', 'CH'],
        'cheque_fecha' => ['Cheque a fecha', 'CtaPagoSAChFec', 'CH'],
        'transferencia' => ['Transferencia', 'CtaPagoTf', 'TR'],
        'deposito' => ['Depósito bancario', null, 'DP'],
        'tarjeta_debito' => ['Tarjeta de débito', 'CtaPagoTDb', 'TD'],
        'tarjeta_credito' => ['Tarjeta de crédito', 'CtaPagoTCr', 'TC'],
        'pasarela' => ['Pago en línea', 'CtaPagoFpay', 'PL'],
    ];

    private ?object $iwparam = null;

    private ?array $config = null;

    public function __construct(private ?string $base = null) {}

    /** La cuenta corriente del cliente: `iwparam.CtaCliente`. */
    public function cuentaCliente(): ?string
    {
        return $this->override('cuenta_cliente')
            ?? $this->deIwparam('CtaCliente');
    }

    /** La del cliente en moneda extranjera. Hoy no se usa: la fase 1 cobra en moneda base. */
    public function cuentaClienteMonedaExtranjera(): ?string
    {
        return $this->override('cuenta_cliente_me')
            ?? $this->deIwparam('CtaCliMonExt');
    }

    /**
     * El mapa completo, con su procedencia.
     *
     * La procedencia no es adorno: es lo que deja que la pantalla enseñe «esto
     * lo dice Softland» frente a «esto lo escribiste tú», y lo que impide que
     * un administrador pise sin darse cuenta lo que el ERP ya tenía resuelto.
     *
     * @return array<string, array{codigo:string,rotulo:string,ttd:string,cuenta:?string,fuente:string}>
     */
    public function medios(): array
    {
        $salida = [];

        foreach (self::MEDIOS as $codigo => [$rotulo, $columna, $ttd]) {
            $propia = $this->override("cuenta_$codigo");
            $erp = $columna ? $this->deIwparam($columna) : null;

            $salida[$codigo] = [
                'codigo' => $codigo,
                'rotulo' => $rotulo,
                'ttd' => $this->override("ttd_$codigo") ?? $ttd,
                'cuenta' => $propia ?? $erp,
                'fuente' => $propia ? 'config' : ($erp ? 'iwparam' : 'falta'),
            ];
        }

        return $salida;
    }

    /** @return array{codigo:string,rotulo:string,ttd:string,cuenta:?string,fuente:string}|null */
    public function medio(string $codigo): ?array
    {
        return $this->medios()[$codigo] ?? null;
    }

    /**
     * El medio que corresponde a un tipo de documento de Softland.
     *
     * Hace falta para leer lo que ya está escrito —un comprobante viejo dice
     * `TR`, no «transferencia»—. Dos medios pueden compartir `TtdCod` (los dos
     * cheques usan `CH`): gana el primero declarado, y por eso esto sirve para
     * mirar, no para decidir qué se escribe.
     */
    public function porTtd(string $ttd): ?array
    {
        foreach ($this->medios() as $medio) {
            if (strcasecmp($medio['ttd'], trim($ttd)) === 0) {
                return $medio;
            }
        }

        return null;
    }

    /**
     * Lo que impide cobrar, dicho antes y no después.
     *
     * Misma idea que `Support\Requisitos` con el servidor: una cuenta que no
     * existe, o que no admite auxiliar, no se descubre cuando el asiento ya
     * está escrito y contabilidad lo está deshaciendo fila por fila.
     *
     * @return list<string>
     */
    public function problemas(): array
    {
        $problemas = [];
        $cliente = $this->cuentaCliente();

        if (! $cliente) {
            $problemas[] = 'No hay cuenta corriente de cliente: `iwparam.CtaCliente` está vacía.';
        } elseif (! $ficha = $this->ficha($cliente)) {
            $problemas[] = "La cuenta del cliente ($cliente) no existe en el plan de cuentas.";
        } else {
            if (strtoupper(trim((string) $ficha->PCAUXI)) !== 'S') {
                $problemas[] = "La cuenta del cliente ($cliente) no maneja auxiliar: el abono quedaría sin dueño.";
            }
            if ((int) $ficha->PCNIVEL < 4) {
                $problemas[] = "La cuenta del cliente ($cliente) no es de último nivel.";
            }
        }

        foreach ($this->medios() as $medio) {
            if (! $medio['cuenta']) {
                continue;   // Un medio sin cuenta no se ofrece; no es un error.
            }
            if (! $this->ficha($medio['cuenta'])) {
                $problemas[] = "La cuenta de {$medio['rotulo']} ({$medio['cuenta']}) no existe en el plan de cuentas.";
            }
            if (! $this->existeTtd($medio['ttd'])) {
                $problemas[] = "El tipo de documento {$medio['ttd']} ({$medio['rotulo']}) no está declarado en cwttdoc.";
            }
        }

        return $problemas;
    }

    /** Los medios que hoy se pueden ofrecer: los que tienen cuenta y tipo de documento. */
    public function disponibles(): array
    {
        return array_values(array_filter(
            $this->medios(),
            fn ($m) => $m['cuenta'] && $this->existeTtd($m['ttd'])
        ));
    }

    // ------------------------------------------------------------ escritura

    /** Guarda los overrides. Campo en blanco = se borra y vuelve a mandar `iwparam`. */
    public function guardar(array $datos): void
    {
        $limpio = $this->overrides();

        foreach ($datos as $clave => $valor) {
            if (! preg_match('/^(cuenta|ttd)_[a-z_]+$/', (string) $clave)) {
                continue;
            }
            $valor = trim((string) $valor);
            if ($valor === '') {
                unset($limpio[$clave]);
            } else {
                $limpio[$clave] = $valor;
            }
        }

        DB::connection('softland')->table('ventas.config')->updateOrInsert(
            ['clave' => self::CLAVE],
            ['valor' => json_encode($limpio, JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
        );

        $this->config = null;
    }

    public function overrides(): array
    {
        if ($this->config === null) {
            $json = DB::connection('softland')->table('ventas.config')
                ->where('clave', self::CLAVE)->value('valor');

            $datos = $json ? json_decode($json, true) : [];
            $this->config = is_array($datos) ? $datos : [];
        }

        return $this->config;
    }

    // -------------------------------------------------------------- privado

    private function override(string $clave): ?string
    {
        $valor = trim((string) ($this->overrides()[$clave] ?? ''));

        return $valor !== '' ? $valor : null;
    }

    private function deIwparam(string $columna): ?string
    {
        if ($this->iwparam === null) {
            $this->iwparam = DB::connection('softland')
                ->table($this->tabla('iwparam'))->first() ?: (object) [];
        }

        $valor = trim((string) ($this->iwparam->{$columna} ?? ''));

        return $valor !== '' ? $valor : null;
    }

    private function ficha(string $cuenta): ?object
    {
        return DB::connection('softland')
            ->table($this->tabla('cwpctas'))
            ->where('PCCODI', $cuenta)
            ->first(['PCCODI', 'PCDESC', 'PCNIVEL', 'PCAUXI']);
    }

    private function existeTtd(string $ttd): bool
    {
        return DB::connection('softland')
            ->table($this->tabla('cwttdoc'))
            ->where('CodDoc', trim($ttd))
            ->exists();
    }

    /** Otra base de la misma instancia, para contrastar sin tocarla. */
    private function tabla(string $nombre): string
    {
        return $this->base ? "{$this->base}.softland.{$nombre}" : "softland.{$nombre}";
    }
}
