<?php

namespace App\Services\Softland;

use App\Models\Usuario;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Escribe cotizaciones y notas de venta en las tablas nativas de Softland.
 *
 * Junto con `ClienteController` es lo único de la app que escribe en Softland,
 * y es lo que más cuidado pide: un documento mal escrito no se nota en la app,
 * se nota cuando alguien factura.
 *
 * ## El correlativo
 *
 * `CotNum` y `NVNumero` **no son IDENTITY** y en toda la base no hay tabla de
 * correlativos: se buscó en `nwparam`, en `cwfoliossueltos`, en
 * `iw_ultcorrelptovta` y en todo lo que se llamara «corr», «folio» o «numer».
 * Softland de escritorio calcula el siguiente por su cuenta, y la app no tiene
 * más remedio que hacer lo mismo.
 *
 * Se hace con el máximo tomado bajo `UPDLOCK, HOLDLOCK` dentro de la
 * transacción: eso serializa a dos teléfonos que graban a la vez. Lo que no
 * puede evitar es que Softland de escritorio, que no toma ese candado, lea el
 * mismo máximo un instante antes. Por eso el alta reintenta si la clave
 * primaria choca: quien pierda la carrera toma el número siguiente.
 *
 * ## Idempotencia
 *
 * El teléfono manda un `client_uuid` por documento. Si llega dos veces — la
 * primera respuesta se perdió en el camino — no se escribe de nuevo: se
 * devuelve el número que se asignó la primera vez, guardado en
 * `ventas.documento_app`.
 *
 * ## Qué queda fuera, y por qué
 *
 *  - **Flete y embalaje**: las columnas existen y se escriben en cero. De las
 *    2.350 cotizaciones de INNOVAGES, **ninguna** los usa, así que no hay un
 *    solo caso real contra el que comprobar cómo entran en el total.
 *  - **Impuestos que no sean el IVA**: `NWCtImpto` tiene 4 filas de ILA entre
 *    2.051. No hay columna en el maestro de productos que diga qué producto lo
 *    paga, así que no se puede deducir. Se escribe el IVA y nada más.
 *  - **Descuentos 2 a 5**: existen en las tablas y no se usan en ninguna de las
 *    2.350 cotizaciones. Se escriben en cero.
 */
class Ventas
{
    public function __construct(private Equivalencia $equivalencia) {}

    private const CONN = 'softland';

    /** Cuántas veces se reintenta si otro proceso se llevó el número. */
    private const REINTENTOS = 5;

    // ------------------------------------------------------------ cotización

    /**
     * Crea una cotización. Devuelve su número.
     *
     * @param  array  $data  ya validado por el controlador
     */
    public function crearCotizacion(array $data, Usuario $u): int
    {
        if ($ya = $this->yaEscrito($data['client_uuid'] ?? null)) {
            return $ya;
        }

        return $this->conReintento(function () use ($data, $u) {
            return $this->conn()->transaction(function () use ($data, $u) {
                $numero = $this->siguienteNumero('softland.nwcotiza', 'CotNum');
                $doc = $this->armar($data);

                $this->conn()->table('softland.nwcotiza')->insert(
                    $this->cabeceraCotizacion($doc, $u) + ['CotNum' => $numero]
                );
                $this->escribirDetalleCotizacion($numero, $doc);
                $this->marcarEscrito($data['client_uuid'] ?? null, 'cotizacion', $numero, $u);

                return $numero;
            });
        });
    }

    /** Reescribe una cotización existente: cabecera, líneas e impuestos. */
    public function actualizarCotizacion(int $numero, array $data, Usuario $u): void
    {
        $this->conn()->transaction(function () use ($numero, $data, $u) {
            $doc = $this->armar($data);

            $this->conn()->table('softland.nwcotiza')->where('CotNum', $numero)
                ->update($this->cabeceraCotizacion($doc, $u));

            $this->conn()->table('softland.nwdetcot')->where('CotNum', $numero)->delete();
            $this->conn()->table('softland.NWCtImpto')->where('CotNum', $numero)->delete();
            $this->escribirDetalleCotizacion($numero, $doc);
        });
    }

    /** Cierra una cotización como perdida, con su motivo de `softland.nwperdida`. */
    public function marcarPerdida(int $numero, string $motivo, ?string $observacion, Usuario $u): void
    {
        $this->conn()->table('softland.nwcotiza')->where('CotNum', $numero)->update([
            'CtEstado' => 'R',
            'CodPerd' => $motivo,
            'ObsPerd' => $observacion,
            'CtFePerd' => now(),
        ] + $this->auditoria($u, false));
    }

    /** Anota un seguimiento. `NroSeg` es correlativo dentro de la cotización. */
    public function anotarSeguimiento(int $numero, array $data, Usuario $u): int
    {
        return $this->conn()->transaction(function () use ($numero, $data) {
            $nro = (int) $this->bloqueado('softland.nwtsegui')
                ->where('CotNum', $numero)->max('NroSeg') + 1;

            $this->conn()->table('softland.nwtsegui')->insert([
                'CotNum' => $numero,
                'NroSeg' => $nro,
                'FecSeg' => now()->startOfDay(),
                'HorSeg' => now(),
                'FecProComp' => $data['proximo_contacto'] ?? null,
                'TipComp' => $data['tipo'] ?? null,
                'Contacto' => $data['contacto'] ?? null,
                'Descripcion' => $data['descripcion'],
            ]);

            return $nro;
        });
    }

    // --------------------------------------------------------- nota de venta

    /**
     * Crea una nota de venta, venga o no de una cotización.
     *
     * Si viene de una, la cotización queda en `V`. Esa marca es la que usa toda
     * la base: las 678 cotizaciones en `V` de INNOVAGES son exactamente las 678
     * que tienen nota de venta, sin una sola excepción.
     */
    public function crearNotaVenta(array $data, Usuario $u, ?int $desdeCotizacion = null): int
    {
        if ($ya = $this->yaEscrito($data['client_uuid'] ?? null)) {
            return $ya;
        }

        return $this->conReintento(function () use ($data, $u, $desdeCotizacion) {
            return $this->conn()->transaction(function () use ($data, $u, $desdeCotizacion) {
                $numero = $this->siguienteNumero('softland.nw_nventa', 'NVNumero');
                $doc = $this->armar($data);

                $this->conn()->table('softland.nw_nventa')->insert(
                    $this->cabeceraNotaVenta($doc, $u, $desdeCotizacion) + ['NVNumero' => $numero]
                );
                $this->escribirDetalleNotaVenta($numero, $doc);

                if ($desdeCotizacion) {
                    $this->conn()->table('softland.nwcotiza')->where('CotNum', $desdeCotizacion)
                        ->update(['CtEstado' => 'V'] + $this->auditoria($u, false));
                }

                $this->marcarEscrito($data['client_uuid'] ?? null, 'nota_venta', $numero, $u);

                return $numero;
            });
        });
    }

    public function actualizarNotaVenta(int $numero, array $data, Usuario $u): void
    {
        $this->conn()->transaction(function () use ($numero, $data, $u) {
            $doc = $this->armar($data);
            $cot = $this->conn()->table('softland.nw_nventa')->where('NVNumero', $numero)->value('CotNum');

            $this->conn()->table('softland.nw_nventa')->where('NVNumero', $numero)
                ->update($this->cabeceraNotaVenta($doc, $u, $cot ?: null));

            $this->conn()->table('softland.nw_detnv')->where('NVNumero', $numero)->delete();
            $this->conn()->table('softland.NW_Impto')->where('nvNumero', $numero)->delete();
            $this->escribirDetalleNotaVenta($numero, $doc);
        });
    }

    /** Estado y fecha de aprobación de una NV. La aprobación en sí vive en `ventas.aprobacion`. */
    public function fijarEstadoNotaVenta(int $numero, string $estado, Usuario $u, bool $aprobada = false): void
    {
        $this->conn()->table('softland.nw_nventa')->where('NVNumero', $numero)->update(
            ['nvEstado' => $estado]
            + ($aprobada ? ['nvFeAprob' => now()] : [])
            + $this->auditoria($u, false)
        );
    }

    // ------------------------------------------------------------- consultas

    /**
     * La tasa de IVA que estampa Softland.
     *
     * Softland no la guarda en ningún maestro: la escribe en cada documento
     * (`NWCtImpto.valpctIni`). Así que se copia la del documento más reciente,
     * que es exactamente lo que hará el próximo. Si no hubiera ninguno — base
     * recién instalada — queda el 19 % de respaldo.
     */
    public function ivaPct(): float
    {
        $v = (float) $this->conn()->table('softland.NWCtImpto')
            ->where('codimpto', 'IVA')->orderByDesc('CotNum')->value('valpctIni');

        return $v > 0 ? $v : Totales::IVA_POR_DEFECTO;
    }

    /**
     * El total que tendría el documento, sin escribir nada.
     *
     * Lo usa la nota de venta para decidir si pasa el tope del vendedor. Tiene
     * que ser la misma aritmética con que después se escribe: comparar el tope
     * contra un total calculado de otra forma deja pasar notas de venta por un
     * peso de diferencia.
     */
    public function totalDe(array $data): float
    {
        return $this->armar($data)['totales']['total'];
    }

    /** El número que se le asignó a un `client_uuid`, si ya se escribió. */
    public function yaEscrito(?string $uuid): ?int
    {
        if (! $uuid) {
            return null;
        }

        $n = $this->conn()->table('ventas.documento_app')->where('client_uuid', $uuid)->value('numero');

        return $n ? (int) $n : null;
    }

    // ---------------------------------------------------------------- armado

    /**
     * Resuelve las líneas contra el maestro de productos y calcula los totales.
     *
     * El teléfono manda el precio en la moneda **del documento**, que es como lo
     * negocia el vendedor. Softland lo guarda en la moneda **del producto**, con
     * el factor al lado. La división ocurre aquí y en ningún otro lado.
     *
     * Lo que el teléfono manda y no se le cree: si un producto es afecto a IVA.
     * Eso sale siempre del maestro. Un teléfono con el catálogo viejo no puede
     * cambiar el IVA de una venta.
     */
    private function armar(array $data): array
    {
        $fecha = $data['fecha'] ?? now()->format('Y-m-d');
        $moneda = $data['moneda'] ?? '01';

        $codigos = array_column($data['lineas'], 'producto');
        $productos = $this->conn()->table('softland.iw_tprod')
            ->whereIn('CodProd', $codigos)
            ->get(['CodProd', 'DesProd', 'CodMonPVta', 'CodUMed', 'Impuesto'])
            ->keyBy(fn ($p) => trim($p->CodProd));

        $lineas = [];
        foreach ($data['lineas'] as $i => $l) {
            $cod = trim($l['producto']);
            $p = $productos[$cod] ?? null;

            if (! $p) {
                throw new RuntimeException("El producto $cod no existe en Softland o no está vigente.");
            }

            $equiv = $this->equivalencia->factor(trim((string) $p->CodMonPVta), $moneda, $fecha);

            $lineas[] = [
                'producto' => $cod,
                'detalle' => $l['detalle'] ?? null,
                'unidad' => $l['unidad'] ?? trim((string) $p->CodUMed),
                'cantidad' => (float) $l['cantidad'],
                // El precio vuelve a la moneda del producto, que es donde lo
                // espera Softland. Si el producto está en UF y el vendedor
                // escribió pesos, aquí se divide por la UF del día.
                'precio' => $equiv != 0 ? ((float) $l['precio']) / $equiv : 0.0,
                'equiv' => $equiv,
                'afecto' => ((int) $p->Impuesto) !== 0,
                'descuento_pct' => (float) ($l['descuento_pct'] ?? 0),
            ];
        }

        $totales = Totales::calcular($lineas, (float) ($data['descuento_pct'] ?? 0), $this->ivaPct());

        return [
            'datos' => $data,
            'fecha' => $fecha,
            'moneda' => $moneda,
            'equiv_documento' => $this->equivalencia->factor($moneda, '01', $fecha),
            'totales' => $totales,
        ];
    }

    private function cabeceraCotizacion(array $doc, Usuario $u): array
    {
        $d = $doc['datos'];
        $t = $doc['totales'];

        return [
            'CodAux' => $d['cliente'],
            'NomCon' => $d['contacto'] ?? null,
            'VenCod' => $d['vendedor'] ?? $u->ven_cod,
            'CodMon' => $doc['moneda'],
            'CodLista' => $d['lista'] ?? $u->cod_lista,
            'CveCod' => $d['condicion'] ?? null,
            'CodiCC' => $d['centro_costo'] ?? null,
            'CtEstado' => $d['estado'] ?? 'N',
            'CtFem' => $doc['fecha'],
            'CtFeEnt' => $d['fecha_entrega'] ?? null,
            // NOT NULL en Softland, con default 0. Nunca se deja en null.
            'numOC' => (string) ($d['oc'] ?? '0'),
            'CtObser' => $d['observacion'] ?? null,
            'CtEquiv' => $doc['equiv_documento'],
            'CtSubTotal' => $t['subtotal'],
            'CtPorcDesc01' => (float) ($d['descuento_pct'] ?? 0),
            'CtDscto01' => $t['descuento'],
            'CtTotalDesc' => $t['descuento'],
            'CtNetoAfecto' => $t['afecto'],
            'CtNetoExento' => $t['exento'],
            'CtMonto' => $t['total'],
        ] + $this->auditoria($u);
    }

    private function cabeceraNotaVenta(array $doc, Usuario $u, ?int $cotizacion): array
    {
        $d = $doc['datos'];
        $t = $doc['totales'];

        return [
            'CotNum' => $cotizacion ?? 0,
            'CodAux' => $d['cliente'],
            'NomCon' => $d['contacto'] ?? null,
            'VenCod' => $d['vendedor'] ?? $u->ven_cod,
            'CodMon' => $doc['moneda'],
            'CodLista' => $d['lista'] ?? $u->cod_lista,
            'CveCod' => $d['condicion'] ?? null,
            // `nwparam.CheckExigeCCostoN = S`: en la nota de venta el centro de
            // costo es obligatorio. El controlador ya lo exigió; esto es el
            // último respaldo antes de escribir.
            'CodiCC' => $d['centro_costo'] ?: $u->cod_cc,
            'CodBode' => $d['bodega'] ?? $u->cod_bode,
            'nvEstado' => $d['estado'] ?? 'N',
            'nvFem' => $doc['fecha'],
            'nvFeEnt' => $d['fecha_entrega'] ?? null,
            'NumOC' => (string) ($d['oc'] ?? '0'),
            'nvObser' => $d['observacion'] ?? null,
            'nvEquiv' => $doc['equiv_documento'],
            'nvSubTotal' => $t['subtotal'],
            'nvPorcDesc01' => (float) ($d['descuento_pct'] ?? 0),
            'nvDescto01' => $t['descuento'],
            'nvTotalDesc' => $t['descuento'],
            'nvNetoAfecto' => $t['afecto'],
            'nvNetoExento' => $t['exento'],
            'nvMonto' => $t['total'],
            'NumReq' => 0,
            'FechaUlMod' => now(),
        ] + $this->auditoria($u);
    }

    private function escribirDetalleCotizacion(int $numero, array $doc): void
    {
        $n = 0;
        foreach ($doc['totales']['lineas'] as $l) {
            $this->conn()->table('softland.nwdetcot')->insert([
                'CotNum' => $numero,
                'CtLinea' => ++$n,
                'CodProd' => $l['producto'],
                'DetProd' => $l['detalle'],
                'CodUMed' => $l['unidad'],
                'CtCant' => $l['cantidad'],
                'CtPrecio' => $l['precio'],
                'CtEquiv' => $l['equiv'],
                'CtSubTotal' => $l['subtotal'],
                'CtDPorcDesc01' => $l['descuento_pct'],
                'CtDDescto01' => $l['descuento'],
                'CtTotDesc' => $l['descuento'],
                'CtTotLinea' => $l['total'],
                'CantUVta' => $l['cantidad'],
                // NOT NULL sin poder quedar en null, aunque no haya kits.
                'CantidadKit' => 0,
                'PorcIncidenciaKit' => 0,
            ]);
        }

        $this->escribirImpuesto('softland.NWCtImpto', 'CotNum', $numero, $doc);
    }

    private function escribirDetalleNotaVenta(int $numero, array $doc): void
    {
        $n = 0;
        foreach ($doc['totales']['lineas'] as $l) {
            $this->conn()->table('softland.nw_detnv')->insert([
                'NVNumero' => $numero,
                'nvLinea' => ++$n,
                'nvCorrela' => $n,
                'CodProd' => $l['producto'],
                'DetProd' => $l['detalle'],
                'CodUMed' => $l['unidad'],
                'nvCant' => $l['cantidad'],
                'nvPrecio' => $l['precio'],
                'nvEquiv' => $l['equiv'],
                'nvSubTotal' => $l['subtotal'],
                'nvDPorcDesc01' => $l['descuento_pct'],
                'nvDDescto01' => $l['descuento'],
                'nvTotDesc' => $l['descuento'],
                'nvTotLinea' => $l['total'],
                'CantUVta' => $l['cantidad'],
                'CantidadKit' => 0,
                'PorcIncidenciaKit' => 0,
            ]);
        }

        $this->escribirImpuesto('softland.NW_Impto', 'nvNumero', $numero, $doc);
    }

    /**
     * La fila del IVA. Es de donde sale el total: en las 2.350 cotizaciones de
     * INNOVAGES `CtMonto = CtSubTotal − CtTotalDesc + Σ Impto`, sin excepción.
     */
    private function escribirImpuesto(string $tabla, string $columna, int $numero, array $doc): void
    {
        $t = $doc['totales'];

        if ($t['afecto'] <= 0) {
            return;
        }

        $this->conn()->table($tabla)->insert([
            $columna => $numero,
            'codimpto' => 'IVA',
            'valpctIni' => $t['iva_pct'],
            'afectoImpto' => $t['afecto'],
            'Impto' => $t['iva'],
        ]);
    }

    // -------------------------------------------------------------- interior

    private function conn()
    {
        return DB::connection(self::CONN);
    }

    /** La misma tabla, pero tomando candado de actualización sobre lo que se lea. */
    private function bloqueado(string $tabla): Builder
    {
        return $this->conn()->table(DB::raw("$tabla WITH (UPDLOCK, HOLDLOCK)"));
    }

    private function siguienteNumero(string $tabla, string $columna): int
    {
        return (int) $this->bloqueado($tabla)->max($columna) + 1;
    }

    /**
     * Reintenta si el número se lo llevó otro.
     *
     * La violación de clave primaria es la señal de que Softland de escritorio
     * grabó entremedio. No es un error que deba ver el vendedor: se vuelve a
     * pedir el máximo, que ahora ya incluye al documento del otro.
     */
    private function conReintento(callable $fn): int
    {
        for ($i = 1; ; $i++) {
            try {
                return $fn();
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($i >= self::REINTENTOS) {
                    throw $e;
                }
                usleep(random_int(20_000, 120_000));
            }
        }
    }

    private function marcarEscrito(?string $uuid, string $tipo, int $numero, Usuario $u): void
    {
        if (! $uuid) {
            return;
        }

        $this->conn()->table('ventas.documento_app')->insert([
            'client_uuid' => $uuid,
            'tipo' => $tipo,
            'numero' => $numero,
            'usuario_id' => $u->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Las columnas de auditoría, como las escribe Softland. `Usuario` es
     * varchar(8): el nombre largo se corta, que es lo que hace el ERP.
     */
    private function auditoria(Usuario $u, bool $creando = true): array
    {
        $cols = [
            'Usuario' => substr((string) ($u->softland_user ?: $u->email), 0, 8),
            // Las mismas dos marcas que deja el ERP: el módulo que escribió y
            // desde dónde. Sirven para reconocer en Softland lo que vino del
            // teléfono sin tener que cruzar con la tabla de la app.
            'sistema' => 'NW',
            'proceso' => 'App de ventas',
        ];

        return $creando ? $cols + ['FechaHoraCreacion' => now()] : $cols;
    }
}
