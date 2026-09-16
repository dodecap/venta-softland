<?php

namespace App\Services\Softland;

use App\Models\Usuario;
use App\Services\Documentos\TipoDocumento;
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
    /**
     * El número que Softland le da a la nota de venta en su mecanismo de
     * atributos. Es el mismo para las tres tablas de valores.
     */
    private const MAESTRO_NV = 4;

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
                // El mismo instante en el documento y en el mapa. Es la huella
                // con que después se reconoce que ese número sigue siendo suyo.
                $creado = now();

                $this->conn()->table('softland.nwcotiza')->insert(
                    $this->cabeceraCotizacion($doc, $u, $creado) + ['CotNum' => $numero]
                );
                $this->escribirDetalleCotizacion($numero, $doc);
                $this->marcarEscrito($data['client_uuid'] ?? null, 'cotizacion', $numero, $u, $creado);

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
                $creado = now();

                $this->conn()->table('softland.nw_nventa')->insert(
                    $this->cabeceraNotaVenta($doc, $u, $desdeCotizacion, $creado) + ['NVNumero' => $numero]
                );
                $this->escribirDetalleNotaVenta($numero, $doc);
                $this->escribirOrigenes($numero, $doc, $desdeCotizacion, $creado);
                $this->escribirAtributos($numero, $data['atributos'] ?? null);

                if ($desdeCotizacion) {
                    $this->conn()->table('softland.nwcotiza')->where('CotNum', $desdeCotizacion)
                        ->update(['CtEstado' => 'V'] + $this->auditoria($u, false));
                }

                $this->marcarEscrito($data['client_uuid'] ?? null, 'nota_venta', $numero, $u, $creado);

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

            // Los enlaces se rehacen con el detalle. Corregir una nota de venta
            // cambia cantidades y puede quitar líneas: dejar los enlaces viejos
            // sería un saldo que ya no corresponde a ninguna línea existente.
            $this->escribirOrigenes($numero, $doc, $cot ? (int) $cot : null, null);
            $this->escribirAtributos($numero, $data['atributos'] ?? null);
        });
    }

    /**
     * Los atributos que la empresa definió para la nota de venta.
     *
     * ## Qué son
     *
     * Campos que declara cada empresa en el ERP, no en el código: INNOVAGES
     * tiene cuatro —«Tipo de Venta», «TIPO DE CLIENTE», «TIPO DE CONTRATO» y la
     * fecha de envío a Santiago—, NETDOMAIN uno, y otra empresa puede no tener
     * ninguno. **Aquí no se nombra ninguno**: se escribe lo que venga, contra la
     * definición que haya en la base.
     *
     * ## Tres tablas, según el tipo
     *
     * `...TVAtrT` guarda la opción elegida de una lista, `...TVAtrF` una fecha y
     * `...TVAtrV` un texto o un número. El `Tipo` de la definición dice cuál: 4
     * lista, 3 fecha, 1 y 2 al texto libre.
     *
     * ## Borrar y volver a poner, siempre
     *
     * La clave primaria de la tabla de listas incluye la opción
     * —`(CodTat, IdMaestro, CodTAtE, Codigo)`—, así que **admite dos valores
     * para el mismo atributo**. Softland nunca lo usa así: de los 146 valores
     * escritos no hay uno solo repetido. Si al corregir se insertara sin borrar,
     * la nota de venta acabaría con «Tipo de Venta: LANPACK ADV» y «Tipo de
     * Venta: VENTA SQL SERVER» a la vez, y el papel elegiría uno al azar.
     *
     * Lo que **no** hay que hacer es limpiar al borrar la nota de venta: de eso
     * se encargan tres triggers de Softland, y se comprobó que funcionan — no
     * hay un solo valor huérfano en las dos empresas.
     *
     * @param  array<int|string, mixed>|null  $atributos  código de atributo => valor.
     *                                                    `null` no toca nada; un valor
     *                                                    vacío borra el que hubiera.
     */
    private function escribirAtributos(int $numero, ?array $atributos): void
    {
        if ($atributos === null) {
            return;
        }

        $definidos = $this->conn()->table('softland.NW_NventaTTAtr')
            ->where('IdMaestro', self::MAESTRO_NV)->get()->keyBy('CodTat');

        foreach ($atributos as $cod => $valor) {
            $def = $definidos[(int) $cod] ?? null;

            // Un atributo que no está definido en **esta** base no se escribe.
            // Es la misma idea de siempre: el teléfono propone, el servidor
            // comprueba contra lo que hay.
            if (! $def) {
                continue;
            }

            $valor = is_string($valor) ? trim($valor) : $valor;
            $vacio = $valor === null || $valor === '' || $valor === [];

            // Se comprueba **antes** de borrar. Al revés —borrar y luego
            // descubrir que la opción no existe— deja el atributo vacío: el
            // valor que había se pierde por mandar uno malo, que es el peor de
            // los dos desenlaces posibles. Lo vimos en el ensayo contra la
            // 2036, y por eso está escrito así.
            if (! $vacio && ! $this->valorAdmisible((int) $def->Tipo, (int) $cod, $valor)) {
                continue;
            }

            $this->borrarAtributo($numero, (int) $cod);

            if ($vacio) {
                continue;
            }

            $this->ponerAtributo($numero, (int) $cod, (int) $def->Tipo, $valor);
        }
    }

    /** Lo que hubiera, en las tres tablas: el valor es uno, viva donde viva. */
    private function borrarAtributo(int $numero, int $cod): void
    {
        foreach (['NW_NventaTVAtrT', 'NW_NventaTVAtrF', 'NW_NventaTVAtrV'] as $tabla) {
            $this->conn()->table('softland.'.$tabla)
                ->where('CodTat', $cod)
                ->where('IdMaestro', self::MAESTRO_NV)
                ->where('Codigo', (string) $numero)
                ->delete();
        }
    }

    /**
     * ¿Este valor se puede escribir en este atributo?
     *
     * En una lista, que la opción exista: apuntar a una que no está dejaría el
     * papel con un hueco donde debería decir «MIGRACION CLOUD - ONPREMISE». En
     * una fecha, que sea una fecha.
     */
    private function valorAdmisible(int $tipo, int $cod, mixed $valor): bool
    {
        if ($tipo === 4) {
            return $this->conn()->table('softland.NW_NventaTVAtr')
                ->where('CodTat', $cod)->where('IdMaestro', self::MAESTRO_NV)
                ->where('CodTAtE', (int) $valor)->exists();
        }

        if ($tipo === 3) {
            return strtotime((string) $valor) !== false;
        }

        return true;
    }

    private function ponerAtributo(int $numero, int $cod, int $tipo, mixed $valor): void
    {
        $comun = ['CodTat' => $cod, 'IdMaestro' => self::MAESTRO_NV, 'Codigo' => (string) $numero];

        if ($tipo === 4) {
            $this->conn()->table('softland.NW_NventaTVAtrT')->insert($comun + ['CodTAtE' => (int) $valor]);

            return;
        }

        if ($tipo === 3) {
            $this->conn()->table('softland.NW_NventaTVAtrF')
                ->insert($comun + ['ValorFecha' => date('Y-m-d', strtotime((string) $valor))]);

            return;
        }

        $this->conn()->table('softland.NW_NventaTVAtrV')
            ->insert($comun + ['Valor' => mb_substr((string) $valor, 0, 50)]);
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

    // ----------------------------------------------------- anular y eliminar

    /**
     * Anula un documento: se queda donde está, conserva su número y deja de
     * contar. Es lo que corresponde cuando el papel ya salió — el cliente
     * tiene un PDF con ese número, y que el número no exista después es peor
     * que que exista anulado.
     *
     * Softland lo registra solo: el trigger de actualización escribe el cambio
     * de estado en `nw_lognwcotiza` con nuestro `proceso`.
     */
    public function anularCotizacion(int $numero, Usuario $u): void
    {
        $this->conn()->table('softland.nwcotiza')->where('CotNum', $numero)
            ->update(['CtEstado' => 'N'] + $this->auditoria($u, false));
    }

    /**
     * Lo mismo en la nota de venta. `N` es «nula» también aquí.
     *
     * **Anular devuelve el saldo igual que borrar.** Una nota de venta anulada
     * deja de consumir la cotización de la que salió, y si no queda ninguna
     * viva, la cotización vuelve a `P`. De las cotizaciones con más de una nota
     * de venta, 43 son exactamente este caso: se anuló la equivocada y se hizo
     * otra con lo mismo.
     */
    public function anularNotaVenta(int $numero, Usuario $u): ?int
    {
        return $this->conn()->transaction(function () use ($numero, $u) {
            $cot = $this->conn()->table('softland.nw_nventa')->where('NVNumero', $numero)->value('CotNum');

            $this->fijarEstadoNotaVenta($numero, 'N', $u);

            return $this->devolverCotizacion($cot ? (int) $cot : null, $u);
        });
    }

    /**
     * Borra la cotización de verdad, y con ella su número vuelve al pozo.
     *
     * **Casi todo el barrido lo hace Softland, no nosotros.** La base trae
     * triggers `FOR DELETE` sobre `nwcotiza` que se llevan el detalle
     * (`NWCotiza_NWDetCot_DTRIG`), los impuestos, los documentos asociados, y
     * que dejan escrito el evento `Elimina` en la bitácora. Repetir ese
     * barrido a mano sería mantener dos veces la misma lógica y equivocarse en
     * una de las dos el día que el ERP se actualice.
     *
     * Lo que los triggers **no** tocan son las dos tablas que además tienen
     * clave foránea `NO_ACTION` hacia la cotización: los seguimientos y los
     * adjuntos. Esas impiden el borrado si quedan filas, así que van antes. Es
     * lo mismo que hace el Softland de escritorio: en INNOVAGES no hay ni un
     * seguimiento huérfano.
     *
     * La bitácora `nw_lognwcotiza` no se toca nunca: tiene 10.397 filas de
     * documentos que ya no existen, y así debe seguir.
     */
    public function eliminarCotizacion(int $numero): void
    {
        $this->conn()->transaction(function () use ($numero) {
            $this->conn()->table('softland.nwtsegui')->where('CotNum', $numero)->delete();
            $this->conn()->table('softland.nwctdoctos')->where('Cotnum', $numero)->delete();
            $this->conn()->table('softland.nwcotiza')->where('CotNum', $numero)->delete();
            $this->olvidar('cotizacion', $numero);
        });
    }

    /**
     * Borra la nota de venta. Aquí no hace falta barrer nada antes: `nw_nventa`
     * no tiene ninguna clave foránea apuntándole, y sus diez triggers de
     * borrado se llevan detalle, impuestos, adjuntos, atributos, la solicitud
     * de aprobación de Softland y su detalle.
     *
     * Lo único que Softland no puede saber es la aprobación por topes, que es
     * nuestra y vive en `ventas.aprobacion`.
     */
    public function eliminarNotaVenta(int $numero, Usuario $u): ?int
    {
        return $this->conn()->transaction(function () use ($numero, $u) {
            $cot = $this->conn()->table('softland.nw_nventa')->where('NVNumero', $numero)->value('CotNum');

            $this->conn()->table('softland.nw_nventa')->where('NVNumero', $numero)->delete();
            $this->conn()->table('ventas.aprobacion')->where('nv_numero', $numero)->delete();
            $this->olvidar('nota_venta', $numero);

            return $this->devolverCotizacion($cot ? (int) $cot : null, $u);
        });
    }

    /**
     * La cotización de la que salía esa nota de venta vuelve a pendiente.
     *
     * `V` no es un desenlace suyo: quiere decir «tiene nota de venta», y la
     * base lo usa así — las 678 cotizaciones en `V` de INNOVAGES son
     * exactamente las 678 que tienen una. Si la nota de venta se borra y la
     * cotización se queda en `V`, miente: aparece vendida, no se puede volver
     * a convertir y no se puede corregir.
     *
     * Sólo se devuelve la que está en `V`. Una perdida (`R`) o anulada (`N`)
     * tuvo su propio desenlace, y ése no lo decide el borrado de otro
     * documento.
     *
     * Y sólo si no le queda **ninguna nota de venta viva**. Una anulada no
     * cuenta: dejar la cotización en `V` por una nota de venta que ya no vale
     * la deja vendida sin estarlo. Con reparto parcial esto pasó a importar de
     * verdad — una cotización puede tener dos notas de venta, y borrar una no
     * puede devolverla a `P` mientras la otra siga en pie.
     */
    private function devolverCotizacion(?int $cot, Usuario $u): ?int
    {
        if (! $cot) {
            return null;
        }

        $quedaViva = $this->conn()->table('softland.nw_nventa')
            ->where('CotNum', $cot)->where('nvEstado', '<>', 'N')->exists();

        if ($quedaViva) {
            return null;
        }

        $tocadas = $this->conn()->table('softland.nwcotiza')
            ->where('CotNum', $cot)->where('CtEstado', 'V')
            ->update(['CtEstado' => 'P'] + $this->auditoria($u, false));

        return $tocadas ? $cot : null;
    }

    /**
     * Por qué esta cotización no se puede eliminar. Arreglo vacío = se puede.
     *
     * Se devuelven **todas** las razones, no la primera: al vendedor le sirve
     * saber de una vez todo lo que estorba.
     */
    public function impedimentosCotizacion(int $numero): array
    {
        $c = $this->conn()->table('softland.nwcotiza')->where('CotNum', $numero)->first(['CtEstado']);

        if (! $c) {
            return ['Esa cotización ya no está en Softland.'];
        }

        $razones = [];
        $estado = strtoupper(trim((string) $c->CtEstado));

        // Softland sí deja borrar una cotización que ya tiene nota de venta: en
        // INNOVAGES hay 14 notas apuntando a una cotización que no existe. La
        // app no lo permite — esa referencia rota nadie la reconstruye después.
        $nv = $this->conn()->table('softland.nw_nventa')->where('CotNum', $numero)->value('NVNumero');

        if ($nv) {
            // Nombrar la nota de venta dice más que repetir que está en `V`.
            $razones[] = 'Ya se convirtió en la nota de venta '.$nv.'.';
        } elseif (! in_array($estado, ['P', 'N'], true)) {
            $razones[] = 'Está '.$this->enMinuscula(TipoDocumento::COTIZACION->estado($estado)).'.';
        }

        return array_merge($razones, $this->impedimentosComunes('cotizacion', $numero, 'la cotización'));
    }

    /** Lo mismo para la nota de venta, que tiene más sitios donde haber avanzado. */
    public function impedimentosNotaVenta(int $numero): array
    {
        $v = $this->conn()->table('softland.nw_nventa')->where('NVNumero', $numero)
            ->first(['nvEstado', 'nvFeAprob']);

        if (! $v) {
            return ['Esa nota de venta ya no está en Softland.'];
        }

        $razones = [];
        $estado = strtoupper(trim((string) $v->nvEstado));

        // `A` no bloquea por sí sola desde que la NV nace aprobada donde el ERP
        // no exige aprobación: si bloqueara, el vendedor no podría borrar la
        // que acaba de escribir. Lo que bloquea es que **alguien la haya
        // aprobado** — eso deja `nvFeAprob` escrito — o que esté concluida.
        $intacta = in_array($estado, ['P', 'N', ''], true)
            || ($estado === 'A' && $v->nvFeAprob === null);

        if (! $intacta) {
            $razones[] = 'Está '.$this->enMinuscula(TipoDocumento::NOTA_VENTA->estado($estado)).'.';
        }

        return array_merge($razones, $this->avancesNotaVenta($numero),
            $this->impedimentosComunes('nota_venta', $numero, 'la nota de venta'));
    }

    /**
     * Dónde ha avanzado ya una nota de venta: facturada, en picking o en una
     * compra. Va aparte porque lo miran dos preguntas distintas — si se puede
     * borrar y si se puede corregir desde el teléfono — y la respuesta es la
     * misma.
     */
    private function avancesNotaVenta(int $numero): array
    {
        $razones = [];

        // Que esté facturada es lo más grave: `iw_gsaen` es el movimiento de
        // facturación. En 209 filas no hay una sola que apunte a una nota de
        // venta borrada, o sea que el ERP tampoco lo permite.
        if ($this->conn()->table('softland.iw_gsaen')->where('nvnumero', $numero)->exists()) {
            $razones[] = 'Ya está facturada.';
        }

        if ($this->conn()->table('softland.iw_encpicking')->where('nvnumero', $numero)->exists()) {
            $razones[] = 'Ya tiene picking en bodega.';
        }

        foreach (['owordencom' => 'NvNumero', 'owrequisicion' => 'NvNumero'] as $tabla => $col) {
            if ($this->conn()->table("softland.$tabla")->where($col, $numero)->exists()) {
                $razones[] = 'Ya generó una compra.';
                break;
            }
        }

        return $razones;
    }

    /**
     * ¿Se puede corregir esta nota de venta desde el teléfono?
     *
     * No es lo mismo que el estado. Desde que la NV nace en `A` allí donde el
     * ERP no pide aprobación, mirar sólo `nvEstado` dejaría al vendedor sin
     * poder tocar la que acaba de escribir. Lo que cierra el documento es que
     * **alguien lo haya aprobado** (`nvFeAprob`), que esté concluido o nulo, o
     * que ya haya avanzado a factura, picking o compra.
     */
    public function corregibleNotaVenta(int $numero): bool
    {
        $v = $this->conn()->table('softland.nw_nventa')->where('NVNumero', $numero)
            ->first(['nvEstado', 'nvFeAprob']);

        if (! $v) {
            return false;
        }

        $estado = strtoupper(trim((string) $v->nvEstado));

        if (! in_array($estado, ['P', 'A', ''], true) || ($estado === 'A' && $v->nvFeAprob !== null)) {
            return false;
        }

        return $this->avancesNotaVenta($numero) === [];
    }

    /** Las dos condiciones que valen igual para los dos documentos. */
    private function impedimentosComunes(string $tipo, int $numero, string $ese): array
    {
        $razones = [];

        if (! $this->esDeLaApp($tipo, $numero)) {
            $razones[] = 'No la creó esta app: bórrala desde Softland.';
        }

        // Emitir el PDF no basta para bloquear — mirar el documento propio no
        // es entregarlo. Lo que bloquea es que haya salido: correo, WhatsApp o
        // descarga, que es cuando `enviado_at` deja de estar en nulo.
        //
        // Y tiene que ser una entrega **de este documento**. El número se
        // reparte de nuevo cuando el anterior se borra, y una entrega heredada
        // dejaba al vendedor sin poder borrar lo que acababa de escribir.
        if ($this->entregado($tipo, $numero)) {
            $razones[] = 'Ya se le entregó al cliente: anula '.$ese.' en vez de borrarla.';
        }

        return $razones;
    }

    /** ¿Alguna versión de **este** documento salió al cliente? */
    private function entregado(string $tipo, int $numero): bool
    {
        $nacimiento = $this->conn()
            ->table($tipo === 'nota_venta' ? 'softland.nw_nventa' : 'softland.nwcotiza')
            ->where($tipo === 'nota_venta' ? 'NVNumero' : 'CotNum', $numero)
            ->value('FechaHoraCreacion');

        return $this->conn()->table('ventas.documento_emision')
            ->where('tipo', $tipo)->where('numero', $numero)
            ->whereNotNull('enviado_at')
            ->get(['creado_en'])
            ->contains(fn ($e) => $this->instante($e->creado_en ?? '') === $this->instante($nacimiento ?? ''));
    }

    /**
     * ¿Este número lo escribió la app, y sigue siendo el mismo documento?
     *
     * Las dos mitades importan. Que exista una fila en el mapa sólo dice que
     * *alguna vez* la app escribió ese número; con el correlativo por máximo,
     * el número pudo repartirse de nuevo.
     */
    public function esDeLaApp(string $tipo, int $numero): bool
    {
        $fila = $this->conn()->table('ventas.documento_app')
            ->where('tipo', $tipo)->where('numero', $numero)
            ->orderByDesc('id')->first();

        return $fila !== null && $this->sigueVivo($fila);
    }

    /** Olvida el mapa de un documento que ya no existe. */
    private function olvidar(string $tipo, int $numero): void
    {
        $this->conn()->table('ventas.documento_app')
            ->where('tipo', $tipo)->where('numero', $numero)->delete();
    }

    private function enMinuscula(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
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

    /**
     * El número que se le asignó a un `client_uuid`, si ya se escribió **y el
     * documento sigue siendo aquél**.
     *
     * Lo segundo no es una precaución teórica. El correlativo es `MAX + 1`, o
     * sea que **el número de un documento borrado se vuelve a repartir**: en
     * INNOVAGES hay 4.453 huecos en las cotizaciones, y durante las pruebas de
     * este proyecto el 8553 llegó a estar asignado a tres documentos seguidos.
     * Un teléfono que estuvo un día sin red y reintenta un `client_uuid` viejo
     * recibía entonces «ya está escrita, es la 8553» y se traía a la pantalla
     * la cotización de otra persona.
     *
     * Si el mapa quedó muerto se borra la fila y se devuelve `null`: el
     * documento se escribe de nuevo, con número nuevo, que es lo que el
     * teléfono venía a pedir.
     */
    public function yaEscrito(?string $uuid): ?int
    {
        if (! $uuid) {
            return null;
        }

        $fila = $this->conn()->table('ventas.documento_app')->where('client_uuid', $uuid)->first();

        if (! $fila) {
            return null;
        }

        if ($this->sigueVivo($fila)) {
            return (int) $fila->numero;
        }

        $this->conn()->table('ventas.documento_app')->where('id', $fila->id)->delete();

        return null;
    }

    /**
     * ¿La fila del mapa sigue apuntando al documento que escribió?
     *
     * La huella es el instante de creación, guardado a la vez en
     * `FechaHoraCreacion` del documento y en `creado_en` del mapa.
     *
     * Se declara muerto **sólo lo que se puede demostrar muerto**: o el
     * documento ya no está, o las dos huellas existen y no coinciden. Sin
     * huella con que comparar —filas escritas antes de que la columna
     * existiera— se da por vivo. Equivocarse por exceso aquí escribiría el
     * documento dos veces, que es peor que un puntero viejo.
     */
    private function sigueVivo(object $fila): bool
    {
        [$tabla, $clave] = $fila->tipo === 'nota_venta'
            ? ['softland.nw_nventa', 'NVNumero']
            : ['softland.nwcotiza', 'CotNum'];

        $doc = $this->conn()->table($tabla)->where($clave, $fila->numero)->first(['FechaHoraCreacion']);

        if (! $doc) {
            return false;
        }

        if (! $fila->creado_en || ! $doc->FechaHoraCreacion) {
            return true;
        }

        return $this->instante($doc->FechaHoraCreacion) === $this->instante($fila->creado_en);
    }

    /** Al segundo: SQL Server y PHP no siempre devuelven la misma precisión. */
    private function instante($v): string
    {
        return $v ? \Carbon\Carbon::parse($v)->format('Y-m-d H:i:s') : '';
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
                // `DetProd` es lo que lee el cliente en el papel y lo que
                // Softland muestra en la línea. Nunca va vacío: si el vendedor
                // no escribió nada, se copia la descripción del maestro, que es
                // exactamente lo que hace el ERP — en las 9.585 líneas de
                // INNOVAGES no hay una sola con `DetProd` nulo.
                'detalle' => $this->detalleDe($l, $p),
                'unidad' => $l['unidad'] ?? trim((string) $p->CodUMed),
                'cantidad' => (float) $l['cantidad'],
                // El precio vuelve a la moneda del producto, que es donde lo
                // espera Softland. Si el producto está en UF y el vendedor
                // escribió pesos, aquí se divide por la UF del día.
                'precio' => $equiv != 0 ? ((float) $l['precio']) / $equiv : 0.0,
                'equiv' => $equiv,
                'afecto' => ((int) $p->Impuesto) !== 0,
                'descuento_pct' => (float) ($l['descuento_pct'] ?? 0),
                // De qué línea de la cotización sale esta, cuando sale de una.
                // Softland no tiene dónde guardarlo —`nwdetcot` no tiene columna
                // de cantidad consumida— y sin eso no se puede saber qué queda
                // por convertir de una cotización repartida entre dos notas de
                // venta. Va a `ventas.linea_origen`.
                'origen' => isset($l['cot_linea']) ? (float) $l['cot_linea'] : null,
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

    /**
     * La cabecera, para insertar o para reescribir.
     *
     * `$creado` sólo llega al crear, y es lo que separa los dos casos: las
     * columnas de creación — quién la hizo y cuándo — se escriben una vez y no
     * se vuelven a tocar. Reescribirlas en cada corrección le cambiaba el autor
     * y la fecha de nacimiento al documento, y dejaba sin huella al mapa.
     */
    private function cabeceraCotizacion(array $doc, Usuario $u, $creado = null): array
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
            // `P` de pendiente. **No `N`**, que en Softland es «nula»: una
            // cotización que nace en `N` nace anulada y el ERP no la lista.
            'CtEstado' => $d['estado'] ?? 'P',
            'CtFem' => $doc['fecha'],
            // Sin fecha de entrega pactada vale la del documento. En las 2.351
            // cotizaciones de INNOVAGES no hay una sola con `CtFeEnt` nulo.
            'CtFeEnt' => ($d['fecha_entrega'] ?? null) ?: $doc['fecha'],
            // NOT NULL en Softland. Vacío es como escribe el ERP el «sin orden
            // de compra»; un «0» ahí se lee como una OC número cero.
            'numOC' => (string) ($d['oc'] ?? ''),
            'CtObser' => $d['observacion'] ?? null,
            'CtEquiv' => $doc['equiv_documento'],
            'CtSubTotal' => $t['subtotal'],
            'CtPorcDesc01' => (float) ($d['descuento_pct'] ?? 0),
            'CtDscto01' => $t['descuento'],
            'CtTotalDesc' => $t['descuento'],
            'CtNetoAfecto' => $t['afecto'],
            'CtNetoExento' => $t['exento'],
            'CtMonto' => $t['total'],
        ] + $this->auditoria($u, $creado !== null, $creado);
    }

    /** Igual que la cotización: `$creado` sólo viene al crear. Ver allá el porqué. */
    private function cabeceraNotaVenta(array $doc, Usuario $u, ?int $cotizacion, $creado = null): array
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
            'CodiCC' => ($d['centro_costo'] ?? null) ?: $u->cod_cc,
            'CodBode' => $d['bodega'] ?? $u->cod_bode,
            // Igual que en la cotización: `N` es «nula». El estado con que
            // nace una NV lo decide la configuración del ERP, no la app.
            'nvEstado' => $d['estado'] ?? $this->estadoInicialNotaVenta(),
            'nvFem' => $doc['fecha'],
            // La que venga de la cotización; si no, la del documento.
            'nvFeEnt' => ($d['fecha_entrega'] ?? null) ?: $doc['fecha'],
            'NumOC' => (string) ($d['oc'] ?? ''),
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
        ] + $this->auditoria($u, $creado !== null, $creado);
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
                // La fecha de la línea es la del documento. Softland la llena
                // siempre; dejarla nula deja la línea sin fecha de compra.
                'CtFecCompr' => $doc['fecha'],
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
                // Cero, no el número de línea: el correlativo de despacho
                // arranca en cero y lo mueve Softland. Está en cero en 2.242 de
                // las 2.244 líneas de INNOVAGES.
                'nvCorrela' => 0,
                'CodProd' => $l['producto'],
                'DetProd' => $l['detalle'],
                'nvFecCompr' => $doc['fecha'],
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
    /**
     * De qué línea de cotización salió cada línea de nota de venta.
     *
     * Es el único enlace del ciclo que hay que guardar por nuestra cuenta: el
     * de nota de venta a factura ya lo tiene Softland en `iw_gmovi.nvCorrela`.
     * Sin éste, una cotización repartida entre dos notas de venta queda en `V`
     * y nadie sabe qué línea se llevó cada una.
     *
     * Se guardan además las dos marcas de creación. El correlativo de Softland
     * es `MAX + 1`, así que un número vuelve a repartirse cuando el documento
     * que lo tenía se borra; sin la marca, un enlace viejo apuntaría al
     * documento de otra persona.
     *
     * Las líneas que el vendedor agregó a mano no traen origen y no dejan fila:
     * no consumen saldo de nada porque no salen de ninguna línea cotizada.
     */
    private function escribirOrigenes(int $numero, array $doc, ?int $cotizacion, $creado): void
    {
        $this->conn()->table('ventas.linea_origen')->where('nv_numero', $numero)->delete();

        if (! $cotizacion) {
            return;
        }

        $cot = $this->conn()->table('softland.nwcotiza')->where('CotNum', $cotizacion)
            ->first(['FechaHoraCreacion']);

        // Al corregir no llega marca nueva: la de la nota de venta es la que ya
        // tiene escrita, no la de ahora.
        $creado ??= $this->conn()->table('softland.nw_nventa')->where('NVNumero', $numero)
            ->value('FechaHoraCreacion');

        $n = 0;
        $filas = [];

        foreach ($doc['totales']['lineas'] as $l) {
            $n++;

            if (($l['origen'] ?? null) === null) {
                continue;
            }

            $filas[] = [
                'nv_numero' => $numero,
                'nv_linea' => $n,
                'nv_creado_en' => $creado,
                'cot_num' => $cotizacion,
                'cot_linea' => (float) $l['origen'],
                'cantidad' => (float) $l['cantidad'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $filas && $this->conn()->table('ventas.linea_origen')->insert(
            array_map(fn ($f) => $f + ['cot_creado_en' => $cot->FechaHoraCreacion ?? null], $filas)
        );
    }

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

    private function marcarEscrito(?string $uuid, string $tipo, int $numero, Usuario $u, $creado = null): void
    {
        if (! $uuid) {
            return;
        }

        $this->conn()->table('ventas.documento_app')->insert([
            'client_uuid' => $uuid,
            'tipo' => $tipo,
            'numero' => $numero,
            // La huella: el mismo valor que quedó en `FechaHoraCreacion`.
            'creado_en' => $creado ?? now(),
            'usuario_id' => $u->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Las columnas de auditoría, como las escribe Softland.
     *
     * Son **dos columnas distintas y se llenan al revés de lo que parece**:
     * quien crea el documento va en `UsuarioGeneraDocto`, y `Usuario` se deja
     * vacío. Así lo escribe el ERP en sus 2.351 cotizaciones, y así hay que
     * escribirlo: es la columna por la que el Softland de escritorio reconoce
     * al autor del documento.
     *
     * Es varchar(8), como el usuario de `wisusuarios`: el nombre largo se
     * corta, que es lo que hace el ERP.
     */
    private function auditoria(Usuario $u, bool $creando = true, $creado = null): array
    {
        // Las dos marcas que deja el ERP: el módulo que escribió y desde
        // dónde. Sirven para reconocer en Softland lo que vino del teléfono
        // sin tener que cruzar con la tabla de la app.
        $cols = ['sistema' => 'NW', 'proceso' => 'App de ventas'];

        if (! $creando) {
            return $cols;
        }

        return $cols + [
            'UsuarioGeneraDocto' => substr((string) ($u->softland_user ?: $u->email), 0, 8),
            'FechaHoraCreacion' => $creado ?? now(),
        ];
    }

    /**
     * El estado con que nace una nota de venta, según el ERP.
     *
     * Lo decide `nwparam.CheckApruebaNv`, y va en el sentido que dice su
     * nombre: **si el ERP exige aprobar (`S`), la NV nace pendiente (`P`)** y
     * espera a que alguien la suelte; si no la exige (`N`), nace **aprobada**
     * (`A`), que es el único estado en que puede empezar algo que nadie tiene
     * que autorizar.
     *
     * Estuvo al revés hasta la 0.6.0, y se veía en los datos: en INNOVAGES
     * `CheckApruebaNv = N` y de las 800 notas de venta de la base **736 están
     * en `A`**, con `nvFeAprob` lleno en apenas 8. O sea que el Softland de
     * escritorio las escribe aprobadas de entrada, no pendientes que luego
     * alguien aprueba — si fuera lo segundo, la fecha de aprobación estaría
     * puesta. Las 16 en `P` son las que de verdad quedaron esperando.
     *
     * Importa más de lo que parece: el panel sólo cuenta como venta la nota
     * aprobada o concluida, así que naciendo en `P` la venta del vendedor no
     * aparecía en su propio panel hasta que alguien tocara el documento en el
     * ERP.
     *
     * Es configuración del cliente, no una constante de la app: la siguiente
     * empresa Softland puede tenerlo al revés.
     */
    public function estadoInicialNotaVenta(): string
    {
        $v = $this->conn()->table('softland.nwparam')->value('CheckApruebaNv');

        return strtoupper(trim((string) $v)) === 'S' ? 'P' : 'A';
    }

    /**
     * Lo que va en `DetProd`: lo que escribió el vendedor o, si no escribió
     * nada, la descripción del maestro.
     *
     * Es editable a propósito. En INNOVAGES hay líneas cuyo `DetProd` no es la
     * descripción del producto — «Portal de RRHH ERP Rental **ADV**» donde el
     * maestro dice «Business» — porque el vendedor ajusta a mano lo que va a
     * leer el cliente. Lo que no puede quedar es vacío.
     */
    private function detalleDe(array $linea, object $producto): string
    {
        $escrito = trim((string) ($linea['detalle'] ?? ''));

        return $escrito !== '' ? $escrito : trim((string) $producto->DesProd);
    }
}
