<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Services\Cobranza\Comprobante;
use App\Services\Cobranza\Recaudacion;
use App\Services\Softland\Maestros;
use App\Services\Softland\Permisos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Cobra de verdad, comprueba lo que quedó escrito, y lo deshace todo.
 *
 * Es la respuesta a «nunca escribir en la base de un cliente sin probar
 * antes», y la única honesta que admite esto. La base de pruebas heredada
 * —`INNOVAGES_TEST`— tiene `cwcpbte` y `cwmovim` vacías y **no tiene
 * `iwparam`, `cwpctas` ni `cwttdoc`**: no hay cuentas que resolver, ni plan de
 * cuentas contra el que comprobarlas, ni los dieciocho disparadores del ERP.
 * Un ensayo ahí no ejercita nada de lo que puede fallar, y salir en verde sin
 * haber probado nada es peor que no probar.
 *
 * Así que se escribe **en la base de verdad, con las mismas funciones que usa
 * el teléfono**, dentro de una transacción que se deshace al final. Pasan los
 * disparadores, pasa el plan de cuentas, pasa el correlativo bajo su candado y
 * pasa la vista del saldo. No queda nada: ni el comprobante, ni su bitácora,
 * ni la fila del mapa.
 *
 * Lo que se comprueba, en este orden:
 *
 *  1. Que el asiento escrito sea **el que `Comprobante` dijo** —campo a campo,
 *     los mismos que compara `cobranza:verifica-comprobante` contra los 114
 *     reales—.
 *  2. Que el asiento **cuadre** ya escrito, leído de la base y no del arreglo.
 *  3. Que **el saldo baje**: la cartera del documento tiene que valer lo de
 *     antes menos lo abonado. Es lo que enlaza las tres piezas —la vista, el
 *     filtro por cuenta y las filas escritas—, y lo que ninguna de las tres
 *     demuestra por su cuenta.
 *  4. Que **repetir el mismo `client_uuid` no escriba un segundo asiento**.
 *  5. Que **borrar lo deje como estaba**: sin cabecera, sin movimientos —los
 *     barre el disparador del ERP— y con el saldo de vuelta.
 *  6. Que **los portazos sean portazos**: un documento que no está en esa
 *     cartera, un importe en cero y un usuario sin el permiso del ERP tienen
 *     que fallar antes de escribir nada.
 *
 * **No tiene modo «escribir de verdad»**, y a propósito: el primer cobro real
 * se hace desde la app, con su usuario y su cliente, no desde un comando que
 * eligió el documento más antiguo que encontró.
 */
class CobranzaEnsayo extends Command
{
    protected $signature = 'cobranza:ensayo
                            {--usuario= : Usuario de Softland a cuyo nombre se cobra (por omisión, el primero con permiso)}
                            {--cliente= : Código de auxiliar; por omisión, el primero de la cartera}
                            {--monto= : Cuánto abonar; por omisión, el saldo entero del documento más antiguo}
                            {--medio=transferencia : Forma de pago}';

    protected $description = 'Escribe un cobro de verdad y lo deshace, para comprobar que sabe escribirlo';

    /** Lo que esta app decide, y por tanto tiene que salir escrito igual. */
    private const CAMPOS_FILA = [
        'AreaCod', 'PctCod', 'CpbMes', 'CvCod', 'VendCod', 'UbicCod', 'CajCod', 'IfCod',
        'DgaCod', 'CcCod', 'TipDocCb', 'NumDocCb', 'CodAux', 'TtdCod', 'NumDoc',
        'MovFe', 'MovFv', 'MovTipDocRef', 'MovNumDocRef', 'MovDebe', 'MovHaber',
        'MonCod', 'MovEquiv', 'MovDebeMa', 'MovHaberMa', 'MovAEquiv', 'FecPag',
        'GrabaDLib', 'MtoTotal', 'Marca', 'Impreso', 'CpbNormaIFRS', 'CpbNormaTrib',
    ];

    private int $fallos = 0;

    private int $hechas = 0;

    public function handle(Recaudacion $recaudacion, Comprobante $motor, Maestros $maestros, Permisos $permisos): int
    {
        $u = $this->quienCobra($permisos);

        if (! $u) {
            $this->error('No hay ningún usuario con permiso de Softland para ingresar comprobantes vigentes.');

            return self::FAILURE;
        }

        $ctx = ['vendedores' => $u->vendedoresVisibles()];
        $doc = $this->documento($maestros, $ctx);

        if (! $doc) {
            $this->error('No hay ningún documento abierto en la cartera al alcance de ese usuario.');

            return self::FAILURE;
        }

        $monto = $this->option('monto') !== null
            ? round((float) $this->option('monto'), 2)
            : round((float) $doc['saldo'], 2);

        $uuid = 'ensayo-'.bin2hex(random_bytes(8));
        $spec = [
            'client_uuid' => $uuid,
            'cliente' => $doc['cliente'],
            'glosa' => 'Ensayo de cobranza',
            'pagos' => [[
                'medio' => (string) $this->option('medio'),
                'numero' => 999999,
                'fecha' => now()->toDateString(),
                'aplicaciones' => [[
                    'tipo' => $doc['tipo'],
                    'numero' => (int) $doc['numero'],
                    'monto' => $monto,
                ]],
            ]],
        ];

        $this->cabecera($u, $doc, $monto);

        $saldoAntes = round((float) $doc['saldo'], 2);
        $conn = DB::connection('softland');
        $conn->beginTransaction();

        try {
            $cobro = $recaudacion->cobrar($spec, $ctx, $u);
            $this->line('');
            $this->info("Comprobante {$cobro['ano']}-{$cobro['numero']} · {$cobro['lineas']} filas · ".$this->plata($cobro['total']));
            $this->line('');

            $this->compruebaAsiento($motor, $spec, $u, $cobro, $doc);
            $this->compruebaCuadratura($cobro);
            $this->compruebaSaldo($maestros, $ctx, $doc, $saldoAntes, $monto);
            $this->compruebaIdempotencia($recaudacion, $spec, $ctx, $u, $cobro);
            $this->compruebaBorrado($recaudacion, $maestros, $ctx, $doc, $saldoAntes, $u, $cobro, $uuid);
            $this->compruebaPortazos($recaudacion, $permisos, $spec, $ctx, $u);
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->line('');
            $this->error('Falló: '.$e->getMessage());

            return self::FAILURE;
        }

        $conn->rollBack();

        $this->line('');

        if ($this->fallos) {
            $this->error("{$this->fallos} de {$this->hechas} comprobaciones fallaron. No queda nada escrito.");

            return self::FAILURE;
        }

        $this->info("Las {$this->hechas} comprobaciones pasan, y la base quedó como estaba.");

        return self::SUCCESS;
    }

    // ------------------------------------------------------- comprobaciones

    /** 1. Lo escrito es lo que `Comprobante` dijo, campo a campo. */
    private function compruebaAsiento(Comprobante $motor, array $spec, Usuario $u, array $cobro, array $doc): void
    {
        // El asiento de contraste se arma aquí con la emisión del documento
        // puesta a mano: `MovFe` es eso y no la fecha del pago, y es el error
        // fácil de todo esto. Si `Recaudacion` escribiera otra cosa, la
        // comparación de abajo lo dice.
        $emision = $doc['emision'] ?? $doc['vencimiento'] ?? null;

        $esperado = $motor->armar([
            'cliente' => $spec['cliente'],
            'fecha' => now()->startOfDay(),
            'glosa' => $spec['glosa'],
            'usuario' => trim((string) $u->softland_user),
            'pagos' => array_map(fn ($p) => array_merge($p, ['aplicaciones' => array_map(
                fn ($a) => array_merge($a, ['fecha_documento' => $emision]), $p['aplicaciones']
            )]), $spec['pagos']),
        ]);

        $filas = DB::connection('softland')->table('softland.cwmovim')
            ->where('CpbAno', $cobro['ano'])->where('CpbNum', $cobro['numero'])
            ->orderBy('MovNum')->get();

        $diferencias = [];

        if (count($filas) !== count($esperado['filas'])) {
            $diferencias[] = 'filas: esperaba '.count($esperado['filas']).' y hay '.count($filas);
        }

        foreach ($filas as $i => $real) {
            foreach (self::CAMPOS_FILA as $campo) {
                $quiere = $esperado['filas'][$i][$campo] ?? null;
                if (! $this->igual($quiere, $real->{$campo})) {
                    $diferencias[] = "fila $i · $campo: esperaba «".$this->texto($quiere).'» y hay «'.$this->texto($real->{$campo}).'»';
                }
            }
        }

        $this->resultado('El asiento escrito es el que dijo Comprobante', $diferencias);
    }

    /** 2. Cuadra, leído de la base. */
    private function compruebaCuadratura(array $cobro): void
    {
        $t = DB::connection('softland')->table('softland.cwmovim')
            ->where('CpbAno', $cobro['ano'])->where('CpbNum', $cobro['numero'])
            ->selectRaw('SUM(MovDebe) d, SUM(MovHaber) h')->first();

        $d = round((float) $t->d, 2);
        $h = round((float) $t->h, 2);

        $this->resultado('El comprobante cuadra en la base',
            $d === $h && $d > 0 ? [] : ["debe $d contra haber $h"]);
    }

    /** 3. El saldo del documento bajó exactamente lo abonado. */
    private function compruebaSaldo(Maestros $maestros, array $ctx, array $doc, float $antes, float $monto): void
    {
        $ahora = $maestros->uno('cartera', [
            'cliente' => $doc['cliente'], 'tipo' => $doc['tipo'], 'numero' => (int) $doc['numero'],
        ], $ctx);

        $queda = round((float) ($ahora['saldo'] ?? 0), 2);
        $esperado = round($antes - $monto, 2);

        // Un documento abonado entero desaparece de la cartera: la vista pide
        // saldo > 0. Que no esté es la respuesta correcta, no un fallo.
        $this->resultado('El saldo de la cartera bajó lo abonado',
            $queda === $esperado ? [] : ["esperaba $esperado y queda $queda"]);
    }

    /** 4. El mismo `client_uuid` no escribe un segundo asiento. */
    private function compruebaIdempotencia(Recaudacion $r, array $spec, array $ctx, Usuario $u, array $cobro): void
    {
        $otra = $r->cobrar($spec, $ctx, $u);
        $cuantos = DB::connection('softland')->table('softland.cwcpbte')
            ->where('CpbAno', $cobro['ano'])->where('CpbNum', $cobro['numero'])->count();

        $problemas = [];

        if ($otra['numero'] !== $cobro['numero'] || $otra['ano'] !== $cobro['ano']) {
            $problemas[] = "la segunda vez devolvió {$otra['ano']}-{$otra['numero']}";
        }
        if ($cuantos !== 1) {
            $problemas[] = "hay $cuantos cabeceras con ese número";
        }

        $this->resultado('Repetir el client_uuid no escribe otro comprobante', $problemas);
    }

    /** 5. Borrarlo lo deja como estaba: sin filas y con el saldo de vuelta. */
    private function compruebaBorrado(Recaudacion $r, Maestros $maestros, array $ctx, array $doc, float $antes, Usuario $u, array $cobro, string $uuid): void
    {
        $r->borrar($cobro['ano'], $cobro['numero'], $u);

        $problemas = [];
        $conn = DB::connection('softland');

        if ($conn->table('softland.cwcpbte')->where('CpbAno', $cobro['ano'])->where('CpbNum', $cobro['numero'])->exists()) {
            $problemas[] = 'la cabecera sigue ahí';
        }
        if ($n = $conn->table('softland.cwmovim')->where('CpbAno', $cobro['ano'])->where('CpbNum', $cobro['numero'])->count()) {
            $problemas[] = "quedaron $n movimientos (el disparador CWCpbte_CWMovim_DTRIG no los barrió)";
        }
        if ($conn->table('ventas.documento_app')->where('client_uuid', $uuid)->exists()) {
            $problemas[] = 'la fila del mapa sigue ahí';
        }

        $ahora = $maestros->uno('cartera', [
            'cliente' => $doc['cliente'], 'tipo' => $doc['tipo'], 'numero' => (int) $doc['numero'],
        ], $ctx);
        $queda = round((float) ($ahora['saldo'] ?? 0), 2);

        if ($queda !== $antes) {
            $problemas[] = "el saldo quedó en $queda y era $antes";
        }

        $this->resultado('Borrarlo lo deja todo como estaba', $problemas);
    }

    /**
     * 6. Lo que no se deja escribir.
     *
     * Van al final y dentro de la misma transacción: no escriben nada —de eso
     * se trata— pero si alguna dejara de ser un portazo, lo que escribiera se
     * deshace igual.
     */
    private function compruebaPortazos(Recaudacion $r, Permisos $permisos, array $spec, array $ctx, Usuario $u): void
    {
        $problemas = [];

        $inventado = $spec;
        $inventado['client_uuid'] = 'ensayo-inventado-'.bin2hex(random_bytes(4));
        $inventado['pagos'][0]['aplicaciones'][0]['numero'] = 99999999;
        $problemas = array_merge($problemas, $this->rebota(
            'un documento que no está en esa cartera', 404,
            fn () => $r->cobrar($inventado, $ctx, $u)));

        $sinAlcance = $spec;
        $sinAlcance['client_uuid'] = 'ensayo-alcance-'.bin2hex(random_bytes(4));
        $problemas = array_merge($problemas, $this->rebota(
            'la cartera de otro vendedor', 404,
            fn () => $r->cobrar($sinAlcance, ['vendedores' => []], $u)));

        // Quien no tiene los permisos del ERP no cobra, tenga el rol que tenga
        // en esta app. Si en esta instalación los tienen todos, no hay contra
        // quién probarlo y se dice, que es distinto de pasar.
        if ($ajeno = $this->sinPermiso($permisos)) {
            $negado = $spec;
            $negado['client_uuid'] = 'ensayo-permiso-'.bin2hex(random_bytes(4));
            $problemas = array_merge($problemas, $this->rebota(
                'un usuario sin «Ingresar Comprobantes»', 403,
                fn () => $r->cobrar($negado, $ctx, $ajeno)));
        } else {
            $this->line('       (nadie sin permiso contra quien probar el 403)');
        }

        $this->resultado('Lo que no se deja escribir, no se escribe', $problemas);
    }

    /** @return list<string> */
    private function rebota(string $que, int $codigo, callable $fn): array
    {
        try {
            $fn();

            return ["$que: no falló, y tenía que dar $codigo"];
        } catch (HttpException $e) {
            return $e->getStatusCode() === $codigo
                ? []
                : ["$que: dio {$e->getStatusCode()} en vez de $codigo"];
        }
    }

    /** Un usuario activo a quien Softland le niega ingresar comprobantes. */
    private function sinPermiso(Permisos $permisos): ?Usuario
    {
        foreach (Usuario::on('softland')->where('activo', true)->orderBy('id')->get() as $u) {
            $s = trim((string) $u->softland_user);

            if ($s !== '' && ! $permisos->puede($s, Permisos::COBRO_AGREGA)) {
                return $u;
            }
        }

        return null;
    }

    // -------------------------------------------------------------- privado

    /** El primer usuario con los permisos del ERP para esto. */
    private function quienCobra(Permisos $permisos): ?Usuario
    {
        $q = Usuario::on('softland')->where('activo', true);

        if ($pedido = $this->option('usuario')) {
            $q->where('softland_user', $pedido);
        }

        foreach ($q->orderBy('id')->get() as $u) {
            $s = trim((string) $u->softland_user);

            if ($s !== ''
                && $permisos->puede($s, Permisos::COBRO_AGREGA)
                && $permisos->puede($s, Permisos::COBRO_VIGENTE)
                && $permisos->puede($s, Permisos::COBRO_ELIMINA)) {
                return $u;
            }
        }

        return null;
    }

    /** El documento a abonar: el pedido, o el más antiguo de la cartera. */
    private function documento(Maestros $maestros, array $ctx): ?array
    {
        $donde = $this->option('cliente') ? ['cliente' => $this->option('cliente')] : [];
        $docs = $maestros->varios('cartera', $donde, $ctx);

        $docs = array_values(array_filter($docs, fn ($d) => (float) $d['saldo'] > 0
            && trim((string) ($d['moneda'] ?? '01')) === Comprobante::MONEDA_BASE));

        usort($docs, fn ($a, $b) => strcmp((string) ($a['vencimiento'] ?? $a['emision'] ?? ''),
            (string) ($b['vencimiento'] ?? $b['emision'] ?? '')));

        return $docs[0] ?? null;
    }

    private function cabecera(Usuario $u, array $doc, float $monto): void
    {
        $this->line('');
        $this->line('  Cobra:     '.$u->nombre.' ('.trim((string) $u->softland_user).')');
        $this->line('  Cliente:   '.$doc['cliente']);
        $this->line('  Documento: '.$doc['tipo'].' Nº '.$doc['numero']
            .' · emitido '.$this->texto($doc['emision'] ?? '—')
            .' · vence '.$this->texto($doc['vencimiento'] ?? '—'));
        $this->line('  Saldo:     '.$this->plata((float) $doc['saldo']));
        $this->line('  Se abona:  '.$this->plata($monto).' con '.$this->option('medio'));
    }

    private function resultado(string $que, array $problemas): void
    {
        $this->hechas++;

        if (! $problemas) {
            $this->line("  <fg=green>ok</>   $que");

            return;
        }

        $this->fallos++;
        $this->line("  <fg=red>NO</>   $que");

        foreach ($problemas as $p) {
            $this->line("       · $p");
        }
    }

    /** Compara como comparan los dos lados: sin espacios, y el número como número. */
    private function igual(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return round((float) $a, 4) === round((float) $b, 4);
        }

        return $this->texto($a) === $this->texto($b);
    }

    private function texto(mixed $v): string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        $t = trim((string) $v);

        // Las fechas vuelven con su hora pegada, con espacio o con «T».
        return preg_match('/^\d{4}-\d{2}-\d{2}[ T]/', $t) ? substr($t, 0, 10) : $t;
    }

    private function plata(float $n): string
    {
        return '$'.number_format($n, 0, ',', '.');
    }
}
