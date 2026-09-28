<?php

namespace App\Services\Cobranza;

use App\Models\Usuario;
use App\Services\Softland\Maestros;
use App\Services\Softland\Permisos;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cobrar: escribir el comprobante de ingreso en la contabilidad de Softland.
 *
 * Es lo único de esta app que **mueve la cuenta corriente de un cliente**, y
 * por eso es lo que más cuidado pide de todo lo que hay escrito hasta aquí: un
 * documento de venta mal escrito se corrige; un asiento duplicado se descubre
 * cuadrando el mes.
 *
 * `Comprobante` dice **qué forma tiene** el asiento —y eso ya está contrastado
 * contra los 114 que existen—. Esto dice **quién puede escribirlo, contra qué
 * se comprueba y qué pasa si llega dos veces**.
 *
 * ## Se escribe en `V` o no se escribe
 *
 * Softland admite dejarlo en `P`, y **le conserva los movimientos**: los ocho
 * documentos que en INNOVAGES parecían pagados de más eran dos borradores que
 * nadie borró. Un borrador no es un guardado a medias más seguro, es un saldo
 * equivocado esperando. Quien no pueda grabar vigente, no cobra desde aquí.
 *
 * ## Los permisos son los del ERP, no los nuestros
 *
 * Los cuatro salen de `cw_cpbte`, el formulario de comprobantes del Softland de
 * escritorio, y se preguntan en `Permisos`. No hay un rol de la app que los
 * sustituya: subirle el rol a alguien aquí no puede darle en contabilidad algo
 * que el administrador del ERP le negó. Ver `Permisos::COBRO_*`.
 *
 * ## Lo que decide el teléfono y lo que decide el servidor
 *
 * El teléfono manda **a quién se le cobra, con qué forma de pago y cuánto se
 * aplica a cada documento**. Todo lo demás lo resuelve el servidor:
 *
 * - **La cuenta contable** de cada forma de pago sale de `iwparam` (`Cuentas`).
 *   Es la misma regla que «el teléfono no decide impuestos».
 * - **La emisión del documento que se paga** —que es `MovFe`, y no la fecha del
 *   pago— se lee de la cartera. Si la mandara el aparato, un dato viejo en
 *   IndexedDB acabaría escrito en el libro.
 * - **El saldo** se comprueba contra `ventas.cartera` en el momento de escribir,
 *   no contra lo que el teléfono creía saber.
 *
 * ## Se ensaya contra la base de verdad, y se deshace
 *
 * No hay base de pruebas que sirva para esto. La heredada —`INNOVAGES_TEST`—
 * tiene `cwcpbte` y `cwmovim` vacías y **no tiene `iwparam`, `cwpctas` ni
 * `cwttdoc`**: no hay de dónde sacar las cuentas ni contra qué comprobarlas, y
 * tampoco lleva los disparadores del ERP, que son media prueba. Un ensayo ahí
 * diría que todo va bien sin haber ejercitado nada de lo que puede fallar.
 *
 * Así que `cobranza:ensayo` escribe **aquí**, con estas mismas funciones, y
 * deshace la transacción: pasan los disparadores, pasa el plan de cuentas,
 * pasa el correlativo bajo su candado, y no queda nada. Por eso esta clase no
 * admite «otra base»: un camino que nunca se recorre no está probado, y éste
 * es el que mueve la cuenta corriente de un cliente.
 *
 * ## Idempotencia
 *
 * Va por `client_uuid`, como todo lo demás, y el mapa es `ventas.documento_app`
 * — con `ano`, porque la clave de un comprobante es año + número.
 *
 * **La comprobación de vuelta es a propósito más laxa que la de la factura.**
 * Allí se exige que el instante de creación coincida; aquí no hay columna de
 * creación que valga —`cwcpbte` sólo tiene `FechaUlMod`, que se mueve si
 * contabilidad toca el asiento— y equivocarse en un sentido no cuesta lo mismo
 * que en el otro: dar por escrito algo que no lo está **duplica un asiento** y
 * eso no se ve hasta que alguien cuadra el mes. Así que se reconoce por lo que
 * no cambia: que el comprobante siga existiendo, que lo lleve nuestra marca en
 * `Proceso`, y que abone a ese mismo cliente ese mismo total.
 */
class Recaudacion
{
    private const CONN = 'softland';

    private const MAPA = 'ventas.documento_app';

    private const CWCPBTE = 'softland.cwcpbte';

    private const CWMOVIM = 'softland.cwmovim';

    private const TIPO = 'cobro';

    /** La marca en `cwcpbte.Proceso`. La misma que deja la factura. */
    public const PROCESO = 'Venta Softland';

    private const REINTENTOS = 5;

    public function __construct(
        private readonly Cuentas $cuentas,
        private readonly Comprobante $motor,
        private readonly Maestros $maestros,
        private readonly Permisos $permisos,
    ) {}

    /**
     * Escribe el cobro y devuelve cómo quedó identificado.
     *
     * @param  array{
     *     client_uuid?: string|null,
     *     cliente: string,
     *     fecha?: string|null,
     *     glosa?: string|null,
     *     pagos: list<array{medio: string, numero?: int|string|null, fecha?: string|null,
     *                       aplicaciones: list<array{tipo: string, numero: int, monto: float}>}>
     * }  $spec
     * @param  array  $ctx  el alcance por vendedor, como lo arma el controlador
     * @return array{ano: string, numero: string, total: float, cliente: string, lineas: int}
     */
    public function cobrar(array $spec, array $ctx, Usuario $u): array
    {
        $softland = trim((string) $u->softland_user);

        $this->exigir($softland, Permisos::COBRO_AGREGA,
            'Softland no te deja ingresar comprobantes (CW · Comprobantes · Ingresar Comprobantes).');

        // Va antes de escribir nada y no después: si no puede grabar vigente,
        // no hay nada que ofrecerle — esta app no deja borradores.
        $this->exigir($softland, Permisos::COBRO_VIGENTE,
            'Softland no te deja grabar comprobantes vigentes (CW · Comprobantes · Permitir Grabar '
            .'Comprobantes Vigentes), y esta app no guarda comprobantes en borrador.');

        $cliente = trim((string) ($spec['cliente'] ?? ''));

        if ($cliente === '') {
            abort(422, 'El cobro no dice de qué cliente es.');
        }

        if ($problemas = $this->cuentas->problemas()) {
            abort(409, 'La cobranza no está configurada: '.$problemas[0]);
        }

        $fecha = Carbon::parse($spec['fecha'] ?? 'today')->startOfDay();

        if ($ya = $this->yaEscrito($spec, $cliente)) {
            return $ya;
        }

        // El intento, ya resuelto contra la cartera: cada abono lleva la
        // emisión del documento que paga, que es lo que va en `MovFe`.
        $intencion = [
            'cliente' => $cliente,
            'fecha' => $fecha,
            'glosa' => $spec['glosa'] ?? null,
            'usuario' => $softland,
            'pagos' => $this->resolverPagos($spec, $cliente, $ctx, $softland),
        ];

        $asiento = $this->motor->armar($intencion);

        return $this->conReintento(function () use ($asiento, $spec, $cliente, $fecha, $u) {
            return DB::connection(self::CONN)->transaction(function () use ($asiento, $spec, $cliente, $fecha, $u) {
                $ano = $fecha->format('Y');
                $mes = $fecha->format('m');

                $numero = $this->motor->numeroSiguiente($ano, $mes);
                $creado = now();

                DB::connection(self::CONN)->table(self::CWCPBTE)->insert(
                    $asiento['cabecera'] + [
                        'CpbAno' => $ano,
                        'CpbNum' => $numero,
                        'CpbNui' => $this->motor->nuiSiguiente($ano, $mes),
                        // El ERP la llena también al insertar: de las 407
                        // cabeceras de INNOVAGES, ninguna la tiene en nulo.
                        'FechaUlMod' => $creado,
                        'SistemaMod' => Comprobante::SISTEMA,
                        'ProcesoMod' => self::PROCESO,
                    ]
                );

                // Después de la cabecera, siempre: `CWMovim_CWCpbte_ITRIG`
                // deshace la transacción entera si el movimiento llega antes.
                foreach ($asiento['filas'] as $fila) {
                    DB::connection(self::CONN)->table(self::CWMOVIM)->insert(
                        $fila + ['CpbAno' => $ano, 'CpbNum' => $numero]
                    );
                }

                $this->marcarEscrito($spec['client_uuid'] ?? null, $ano, $numero, $creado, $u);

                return [
                    'ano' => $ano,
                    'numero' => $numero,
                    'cliente' => $cliente,
                    'total' => round($asiento['haber'], 2),
                    'lineas' => count($asiento['filas']),
                ];
            });
        });
    }

    /**
     * Borra un comprobante escrito por la app.
     *
     * **No es anular**: en contabilidad no existe tal cosa —`CpbEst` sólo tiene
     * vigente y borrador, y las 407 cabeceras de INNOVAGES llevan el par de
     * reverso en ceros, así que no hay un solo reverso real contra el que
     * contrastar uno nuestro—. Lo que el Softland de escritorio ofrece para
     * deshacer un comprobante es borrarlo, y eso es lo que se ofrece aquí.
     *
     * Sólo lo nuestro, y eso lo dice `Proceso`. Los movimientos no hay que
     * tocarlos: `CWCpbte_CWMovim_DTRIG` los barre al irse la cabecera.
     */
    public function borrar(string $ano, string $numero, Usuario $u): void
    {
        $softland = trim((string) $u->softland_user);

        $this->exigir($softland, Permisos::COBRO_ELIMINA,
            'Softland no te deja eliminar comprobantes (CW · Comprobantes · Eliminar Comprobantes).');

        DB::connection(self::CONN)->transaction(function () use ($ano, $numero) {
            $cab = DB::connection(self::CONN)->table(self::CWCPBTE)
                ->where('CpbAno', $ano)->where('CpbNum', $numero)
                ->first(['Proceso']);

            if (! $cab) {
                abort(404, 'Ese comprobante ya no está.');
            }

            if (trim((string) $cab->Proceso) !== self::PROCESO) {
                abort(403, 'Ese comprobante no lo escribió esta app, así que no se borra desde aquí.');
            }

            DB::connection(self::CONN)->table(self::CWCPBTE)
                ->where('CpbAno', $ano)->where('CpbNum', $numero)->delete();

            DB::connection(self::CONN)->table(self::MAPA)
                ->where('tipo', self::TIPO)->where('ano', $ano)
                ->where('numero', (int) $numero)->delete();
        });
    }

    /**
     * El comprobante, para enseñarlo: su cabecera y lo que abonó.
     *
     * @return array{ano:string,numero:string,fecha:string,glosa:?string,estado:string,
     *               usuario:?string,nuestro:bool,total:float,abonos:list<array>,pagos:list<array>}|null
     */
    public function ver(string $ano, string $numero): ?array
    {
        $cab = DB::connection(self::CONN)->table(self::CWCPBTE)
            ->where('CpbAno', $ano)->where('CpbNum', $numero)->first();

        if (! $cab) {
            return null;
        }

        $filas = DB::connection(self::CONN)->table(self::CWMOVIM)
            ->where('CpbAno', $ano)->where('CpbNum', $numero)
            ->orderBy('MovNum')->get();

        $abonos = $pagos = [];

        foreach ($filas as $f) {
            if ((float) $f->MovHaber > 0) {
                $abonos[] = [
                    'cliente' => trim((string) $f->CodAux),
                    'tipo' => trim((string) $f->MovTipDocRef),
                    'numero' => (int) $f->MovNumDocRef,
                    'monto' => (float) $f->MovHaber,
                    'medio' => $this->medioDe((string) $f->TtdCod),
                    'referencia' => (int) $f->NumDoc ?: null,
                    'fecha_pago' => $this->soloFecha($f->MovFv),
                ];
            } else {
                $pagos[] = [
                    'medio' => $this->medioDe((string) $f->TipDocCb),
                    'referencia' => (int) $f->NumDocCb ?: null,
                    'monto' => (float) $f->MovDebe,
                ];
            }
        }

        return [
            'ano' => $ano,
            'numero' => $numero,
            'fecha' => $this->soloFecha($cab->CpbFec),
            'glosa' => $cab->CpbGlo,
            'estado' => trim((string) $cab->CpbEst),
            'usuario' => trim((string) $cab->Usuario) ?: null,
            'nuestro' => trim((string) $cab->Proceso) === self::PROCESO,
            'total' => round(array_sum(array_column($abonos, 'monto')), 2),
            'abonos' => $abonos,
            'pagos' => $pagos,
        ];
    }

    // -------------------------------------------------------------- privado

    /**
     * Cada abono, contrastado con la cartera de verdad.
     *
     * Aquí es donde el cobro deja de ser lo que el teléfono creía y pasa a ser
     * lo que la base dice hoy: el documento tiene que existir, ser de ese
     * cliente, estar dentro del alcance de quien cobra y —salvo permiso
     * expreso del ERP— no admitir más de lo que debe.
     */
    private function resolverPagos(array $spec, string $cliente, array $ctx, string $softland): array
    {
        $sobreSaldo = $this->permisos->puede($softland, Permisos::COBRO_SOBRE_SALDO);
        $porDocumento = [];
        $pagos = [];

        foreach ($spec['pagos'] ?? [] as $pago) {
            $aplicaciones = [];

            foreach ($pago['aplicaciones'] ?? [] as $ap) {
                $tipo = trim((string) ($ap['tipo'] ?? ''));
                $numero = (int) ($ap['numero'] ?? 0);
                $monto = round((float) ($ap['monto'] ?? 0), 2);
                $doc = $this->documento($cliente, $tipo, $numero, $ctx);

                if (! $doc) {
                    abort(404, "El documento $tipo Nº $numero no está en la cartera de ese cliente.");
                }

                if (trim((string) ($doc['moneda'] ?? '01')) !== Comprobante::MONEDA_BASE) {
                    abort(422, "El documento $tipo Nº $numero no está en moneda base; eso todavía no se cobra desde la app.");
                }

                $clave = "$tipo-$numero";
                $porDocumento[$clave] = ($porDocumento[$clave] ?? 0) + $monto;

                if (! $sobreSaldo && round($porDocumento[$clave] - (float) $doc['saldo'], 2) > 0) {
                    abort(422, "Al documento $tipo Nº $numero le quedan "
                        .number_format((float) $doc['saldo'], 0, ',', '.')
                        .' y se está abonando más. Softland no te concede «Permite pagar más del saldo».');
                }

                $aplicaciones[] = [
                    'tipo' => $tipo,
                    'numero' => $numero,
                    'monto' => $monto,
                    // La emisión del documento que se paga, leída de la base y
                    // no del aparato: es `MovFe`, y no es la fecha del pago.
                    'fecha_documento' => $doc['emision'] ?? $doc['vencimiento'] ?? null,
                    'glosa' => $ap['glosa'] ?? null,
                ];
            }

            $pagos[] = [
                'medio' => $pago['medio'] ?? '',
                'numero' => $pago['numero'] ?? 0,
                'fecha' => $pago['fecha'] ?? ($spec['fecha'] ?? null),
                'aplicaciones' => $aplicaciones,
            ];
        }

        return $pagos;
    }

    /** Una fila de la cartera, con el alcance por vendedor puesto. */
    private function documento(string $cliente, string $tipo, int $numero, array $ctx): ?array
    {
        return $this->maestros->uno('cartera', [
            'cliente' => $cliente,
            'tipo' => $tipo,
            'numero' => $numero,
        ], $ctx);
    }

    /**
     * El comprobante que este `client_uuid` ya escribió, si sigue siendo ése.
     *
     * Ver la nota de la cabecera de la clase sobre por qué la huella es el
     * cliente y el total, y no el instante de creación.
     *
     * @return array{ano:string,numero:string,cliente:string,total:float,lineas:int}|null
     */
    private function yaEscrito(array $spec, string $cliente): ?array
    {
        $uuid = $spec['client_uuid'] ?? null;

        if (! $uuid) {
            return null;
        }

        $fila = DB::connection(self::CONN)->table(self::MAPA)
            ->where('client_uuid', $uuid)->orderByDesc('id')->first();

        if (! $fila || $fila->tipo !== self::TIPO) {
            return null;
        }

        $ano = (string) $fila->ano;
        $numero = str_pad((string) $fila->numero, 8, '0', STR_PAD_LEFT);
        $visto = $this->ver($ano, $numero);
        $pedido = $this->totalPedido($spec);

        if ($visto && $visto['nuestro']
            && $visto['abonos']
            && $visto['abonos'][0]['cliente'] === $cliente
            && round($visto['total'] - $pedido, 2) === 0.0) {
            return [
                'ano' => $ano,
                'numero' => $numero,
                'cliente' => $cliente,
                'total' => $visto['total'],
                'lineas' => count($visto['abonos']) + count($visto['pagos']),
            ];
        }

        // El mapa apunta a algo que ya no es este cobro. Se olvida y se escribe
        // de nuevo, que es lo que el teléfono vino a pedir.
        DB::connection(self::CONN)->table(self::MAPA)->where('id', $fila->id)->delete();

        return null;
    }

    private function totalPedido(array $spec): float
    {
        $total = 0.0;

        foreach ($spec['pagos'] ?? [] as $pago) {
            foreach ($pago['aplicaciones'] ?? [] as $ap) {
                $total += round((float) ($ap['monto'] ?? 0), 2);
            }
        }

        return round($total, 2);
    }

    private function marcarEscrito(?string $uuid, string $ano, string $numero, $creado, Usuario $u): void
    {
        if (! $uuid) {
            return;
        }

        DB::connection(self::CONN)->table(self::MAPA)->insert([
            'client_uuid' => $uuid,
            'tipo' => self::TIPO,
            'ano' => $ano,
            'numero' => (int) $numero,
            'creado_en' => $creado,
            'usuario_id' => $u->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function exigir(string $usuario, array $permiso, string $mensaje): void
    {
        if (! $this->permisos->puede($usuario, $permiso)) {
            abort(403, $mensaje);
        }
    }

    /**
     * Reintenta si el número se lo llevó otro.
     *
     * Igual que en las ventas: `UPDLOCK, HOLDLOCK` serializa a dos teléfonos,
     * pero no al Softland de escritorio, que no toma ese candado. La violación
     * de clave primaria es la señal de que alguien grabó entremedio, y lo que
     * toca es volver a pedir el máximo.
     */
    private function conReintento(callable $fn): array
    {
        for ($i = 1; ; $i++) {
            try {
                return $fn();
            } catch (UniqueConstraintViolationException $e) {
                if ($i >= self::REINTENTOS) {
                    throw $e;
                }
                usleep(random_int(20_000, 120_000));
            }
        }
    }

    /** El código de medio de pago de un `TtdCod`, si esta app lo conoce. */
    private function medioDe(string $ttd): ?string
    {
        return $this->cuentas->porTtd($ttd)['codigo'] ?? null;
    }

    private function soloFecha(mixed $valor): ?string
    {
        return $valor ? substr((string) $valor, 0, 10) : null;
    }
}
