<?php

namespace App\Services\Softland;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Los maestros de Softland servidos por página, para que el teléfono se los
 * lleve a terreno.
 *
 * Todo lo que la app descarga sale de esta tabla declarativa: qué tabla de
 * Softland es, qué columnas se copian y con qué nombre, qué filas se dejan
 * fuera, y por qué columna se sabe que una fila cambió. Agregar un maestro es
 * agregar una entrada aquí; no hay que tocar el controlador ni las rutas.
 *
 * Solo lectura: los maestros los mantiene Softland. La única excepción del
 * proyecto son los clientes y sus contactos, que sí se escriben, y eso vive en
 * `ClienteController` — no aquí.
 *
 * Dos cosas que condicionan el diseño, medidas contra INNOVAGES:
 *
 *  1. `FechaUlMod` viene NULL en 2.307 de los 3.824 clientes. Sirve para traer
 *     lo que cambió desde la última vez, pero **no** para saber qué hay en
 *     total: una fila sin fecha solo llega en la descarga completa.
 *  2. Softland borra filas de verdad, y un borrado no deja rastro que se pueda
 *     consultar. Por eso cada respuesta trae `total`: si al terminar la
 *     descarga incremental al teléfono le sobran o le faltan filas, se baja el
 *     maestro completo. Es la única forma barata de no quedarse con fantasmas.
 */
class Maestros
{
    /** Página por defecto y techo. Más de 2.000 filas de cliente ya son ~600 KB. */
    public const LIMITE = 500;

    public const LIMITE_MAX = 2000;

    /** Meses de cotizaciones y notas de venta que se lleva el teléfono. */
    public const MESES_HISTORIA = 12;

    public static function recursos(): array
    {
        return [
            // ---- Catálogos chicos: se descargan enteros, no tienen fecha ----
            'vendedores' => [
                'titulo' => 'Vendedores',
                'tabla' => 'softland.cwtvend',
                'clave' => ['VenCod'],
                'campos' => ['codigo' => 'VenCod', 'nombre' => 'VenDes', 'email' => 'EMail'],
                'etiqueta' => 'nombre',
            ],
            'bodegas' => [
                'titulo' => 'Bodegas',
                'tabla' => 'softland.iw_tbode',
                'clave' => ['CodBode'],
                'campos' => ['codigo' => 'CodBode', 'nombre' => 'DesBode'],
                'etiqueta' => 'nombre',
            ],
            'listas_precio' => [
                'titulo' => 'Listas de precio',
                'tabla' => 'softland.iw_tlispre',
                'clave' => ['CodLista'],
                'campos' => ['codigo' => 'CodLista', 'nombre' => 'DesLista', 'tipo' => 'TipoLista'],
                'etiqueta' => 'nombre',
            ],
            'condiciones_venta' => [
                'titulo' => 'Condiciones de venta',
                'tabla' => 'softland.cwtconv',
                'clave' => ['CveCod'],
                'campos' => ['codigo' => 'CveCod', 'nombre' => 'CveDes', 'dias' => 'CveDias:entero'],
                'etiqueta' => 'nombre',
            ],
            'motivos_perdida' => [
                'titulo' => 'Motivos de pérdida',
                'tabla' => 'softland.nwperdida',
                'clave' => ['CodPerd'],
                'campos' => ['codigo' => 'CodPerd', 'nombre' => 'desPerd'],
                'etiqueta' => 'nombre',
            ],
            'monedas' => [
                'titulo' => 'Monedas',
                'tabla' => 'softland.cwtmone',
                'clave' => ['CodMon'],
                'campos' => [
                    'codigo' => 'CodMon', 'nombre' => 'DesMon', 'simbolo' => 'SimMon',
                    'decimales' => 'DecMon:entero',
                ],
                'etiqueta' => 'nombre',
            ],
            'unidades' => [
                'titulo' => 'Unidades de medida',
                'tabla' => 'softland.iw_tumed',
                'clave' => ['CodUMed'],
                'campos' => ['codigo' => 'CodUMed', 'nombre' => 'DesUMed'],
                'etiqueta' => 'nombre',
            ],
            'grupos' => [
                'titulo' => 'Grupos de producto',
                'tabla' => 'softland.iw_tgrupo',
                'clave' => ['CodGrupo'],
                'campos' => ['codigo' => 'CodGrupo', 'nombre' => 'DesGrupo'],
                'etiqueta' => 'nombre',
            ],

            // ---- Centros de costo: 594 activos, ya no caben en un desplegable ----
            'centros_costo' => [
                'titulo' => 'Centros de costo',
                'tabla' => 'softland.cwtccos',
                'clave' => ['CodiCC'],
                // Columnas `CodiCC`/`DescCC`: esta tabla no sigue el prefijo de
                // tres letras del resto de los maestros de Softland.
                'campos' => ['codigo' => 'CodiCC', 'nombre' => 'DescCC', 'nivel' => 'NivelCC:entero'],
                'filtro' => fn (Builder $q) => $q->where('Activo', 'S'),
                'etiqueta' => 'nombre',
            ],

            // ---- Ubicación y giro: se usan para leer y para dar de alta clientes ----
            'giros' => [
                'titulo' => 'Giros',
                'tabla' => 'softland.cwtgiro',
                'clave' => ['GirCod'],
                'campos' => ['codigo' => 'GirCod', 'nombre' => 'GirDes'],
                'etiqueta' => 'nombre',
            ],
            'comunas' => [
                'titulo' => 'Comunas',
                'tabla' => 'softland.cwtcomu',
                'clave' => ['ComCod'],
                'campos' => ['codigo' => 'ComCod', 'nombre' => 'ComDes', 'region' => 'id_Region:entero'],
                'etiqueta' => 'nombre',
            ],
            'cargos' => [
                'titulo' => 'Cargos de contacto',
                'tabla' => 'softland.cwtcarg',
                'clave' => ['CarCod'],
                'campos' => ['codigo' => 'CarCod', 'nombre' => 'CarNom'],
                'etiqueta' => 'nombre',
            ],
            'regiones' => [
                'titulo' => 'Regiones',
                'tabla' => 'softland.cwtregion',
                'clave' => ['id_Region'],
                'campos' => ['codigo' => 'id_Region:entero', 'nombre' => 'Descripcion'],
                'etiqueta' => 'nombre',
            ],
            'ciudades' => [
                'titulo' => 'Ciudades',
                'tabla' => 'softland.cwtciud',
                'clave' => ['CiuCod'],
                'campos' => ['codigo' => 'CiuCod', 'nombre' => 'CiuDes', 'region' => 'id_Region:entero'],
                'etiqueta' => 'nombre',
            ],

            // ---- Los grandes ----
            'clientes' => [
                'titulo' => 'Clientes',
                'tabla' => 'softland.cwtauxi',
                'clave' => ['CodAux'],
                'fecha' => 'FechaUlMod',
                'campos' => [
                    'codigo' => 'CodAux',
                    'nombre' => 'NomAux',
                    'fantasia' => 'NoFAux',
                    'rut' => 'RutAux',
                    'giro' => 'GirAux',
                    'direccion' => 'DirAux',
                    'comuna' => 'ComAux',
                    'ciudad' => 'CiuAux',
                    'fono' => 'FonAux1',
                    'email' => 'EMail',
                    'email_dte' => 'eMailDTE',
                    'dias_plazo' => 'DiaPlazo:entero',
                    'bloqueado' => 'Bloqueado:sn',
                    'activo' => 'ActAux:sn',
                    'modificado' => 'FechaUlMod:fecha',
                ],
                // `cwtauxi` es la tabla de auxiliares: mezcla clientes, proveedores,
                // empleados y socios en las columnas Cla*. Sin este filtro el
                // vendedor vería 451 proveedores en su lista de clientes.
                'filtro' => fn (Builder $q) => $q->where('ClaCli', 'S'),
                'etiqueta' => 'nombre',
            ],
            'contactos' => [
                'titulo' => 'Contactos',
                'tabla' => 'softland.cwtaxco',
                // No hay id: la clave es cliente + nombre, y así la trata Softland
                // (`nwcotiza.NomCon` guarda el nombre, no un id).
                'clave' => ['CodAuc', 'NomCon'],
                // `FechaUlMod` existe pero está poblada en 139 de 2.816 filas: no
                // sirve de reloj. Son pocas: se descargan enteras cada vez.
                'campos' => [
                    'cliente' => 'CodAuc',
                    'nombre' => 'NomCon',
                    'cargo' => 'CarCon',
                    'fono' => 'FonCon',
                    'email' => 'Email',
                ],
                'etiqueta' => 'nombre',
            ],
            'productos' => [
                'titulo' => 'Productos',
                'tabla' => 'softland.iw_tprod',
                'clave' => ['CodProd'],
                'fecha' => 'FechaUlMod',
                'campos' => [
                    'codigo' => 'CodProd',
                    'nombre' => 'DesProd',
                    'nombre2' => 'DesProd2',
                    'barra' => 'CodBarra',
                    'unidad' => 'CodUMed',
                    'grupo' => 'CodGrupo',
                    'moneda' => 'CodMonPVta',
                    'precio' => 'PrecioVta:decimal',
                    'precio_boleta' => 'PrecioBol:decimal',
                    'afecto' => 'Impuesto:flag',
                    'modificado' => 'FechaUlMod:fecha',
                ],
                'filtro' => fn (Builder $q) => $q
                    ->where('Inactivo', 0)
                    ->where('esParaVenta', '<>', 0)
                    // Softland deja un producto comodín «*» con la descripción
                    // llena de asteriscos. No es vendible y ensucia el buscador.
                    ->where('CodProd', '<>', '*'),
                'etiqueta' => 'nombre',
            ],
            'precios' => [
                'titulo' => 'Precios por lista',
                'tabla' => 'softland.iw_tlprprod',
                'clave' => ['CodLista', 'CodProd'],
                'campos' => [
                    'lista' => 'CodLista',
                    'producto' => 'CodProd',
                    'valor' => 'ValorPct:decimal',
                    'unidad' => 'CodUmed',
                ],
                // Filas en 0 hay 1.383 de 2.222: son producto sin precio de lista,
                // no precio cero. Se descartan y el precio queda en el del maestro.
                'filtro' => fn (Builder $q) => $q->where('ValorPct', '<>', 0),
                'etiqueta' => 'producto',
            ],

            /*
             * ---- Documentos ----
             *
             * Cotizaciones y notas de venta también se descargan: el vendedor
             * necesita mirar en terreno lo que ya cotizó, y ahí no hay señal.
             *
             * Van sin `fecha` a propósito, o sea se bajan enteras cada vez. La
             * razón es que `nwcotiza` solo tiene `FechaHoraCreacion`: cuando una
             * cotización pasa a vendida o a perdida, esa columna no se mueve, así
             * que una descarga incremental dejaría el estado congelado — que es
             * justo el dato que se va a mirar. Cabe: en los últimos 12 meses hay
             * 184 cotizaciones y 51 notas de venta en INNOVAGES.
             */
            'cotizaciones' => [
                'titulo' => 'Cotizaciones',
                'tabla' => 'softland.nwcotiza',
                'clave' => ['CotNum'],
                'campos' => [
                    'numero' => 'CotNum:entero',
                    'cliente' => 'CodAux',
                    'contacto' => 'NomCon',
                    'vendedor' => 'VenCod',
                    'moneda' => 'CodMon',
                    'lista' => 'CodLista',
                    'condicion' => 'CveCod',
                    'centro_costo' => 'CodiCC',
                    'estado' => 'CtEstado',
                    'fecha' => 'CtFem:fecha',
                    'fecha_entrega' => 'CtFeEnt:fecha',
                    'oc' => 'numOC',
                    'observacion' => 'CtObser',
                    'neto' => 'CtNetoAfecto:decimal',
                    'exento' => 'CtNetoExento:decimal',
                    'descuento' => 'CtTotalDesc:decimal',
                    'flete' => 'CtValflete:decimal',
                    'embalaje' => 'CtValEmb:decimal',
                    'total' => 'CtMonto:decimal',
                    'motivo_perdida' => 'CodPerd',
                    'creado' => 'FechaHoraCreacion:fecha',
                ],
                'filtro' => function (Builder $q, array $ctx, bool $ventana = true) {
                    if ($ventana) {
                        $q->where('CtFem', '>=', static::desdeHistoria());
                    }
                    static::soloSusVendedores($q, 'VenCod', $ctx);
                },
            ],
            'cotizacion_lineas' => [
                'titulo' => 'Detalle de cotizaciones',
                'tabla' => 'softland.nwdetcot',
                'clave' => ['CotNum', 'CtLinea'],
                'campos' => [
                    'cotizacion' => 'CotNum:entero',
                    'linea' => 'CtLinea:decimal',
                    'producto' => 'CodProd',
                    'detalle' => 'DetProd',
                    'unidad' => 'CodUMed',
                    'cantidad' => 'CtCant:decimal',
                    'precio' => 'CtPrecio:decimal',
                    // Factor de la moneda del producto a la del documento. No
                    // es un detalle contable: un tercio de las líneas de
                    // INNOVAGES lo tiene distinto de 1 porque el producto está
                    // en UF y el documento en pesos. Sin esto la app muestra
                    // «1 UNIDAD × $ 5 = $ 214.735».
                    'equiv' => 'CtEquiv:decimal',
                    'descuento' => 'CtTotDesc:decimal',
                    'total' => 'CtTotLinea:decimal',
                ],
                'filtro' => fn (Builder $q, array $ctx, bool $ventana = true) => $q->whereIn(
                    'CotNum', static::cabecerasVisibles('softland.nwcotiza', 'CotNum', 'CtFem', 'VenCod', $ctx, $ventana)
                ),
            ],
            /*
             * Facturas, boletas y notas de crédito electrónicas.
             *
             * Se identifican por `Tipo` + `NroInt`, que es la clave de Softland;
             * el **folio** es lo que ve la gente y lo que dice el SII, pero no
             * es único entre tipos: hay folios que existen a la vez como factura
             * y como boleta.
             *
             * Bajan al teléfono por dos razones. Una es enseñarlas. La otra pesa
             * más: **el avance de una nota de venta se calcula desde aquí**. Las
             * siete columnas de avance de `nw_detnv` están muertas —cero
             * registros en las dos empresas—, así que «facturado 5 de 12» sale
             * de sumar las líneas de factura vigentes que apuntan a esa línea.
             */
            'facturas' => [
                'titulo' => 'Facturas y notas de crédito',
                'tabla' => 'softland.iw_gsaen',
                'clave' => ['Tipo', 'NroInt'],
                'campos' => [
                    'tipo' => 'Tipo',
                    'numero_interno' => 'NroInt:entero',
                    'subtipo' => 'SubTipoDocto',
                    'folio' => 'Folio:entero',
                    'cliente' => 'CodAux',
                    'vendedor' => 'CodVendedor',
                    'moneda' => 'CodMoneda',
                    'estado' => 'Estado',
                    'fecha' => 'Fecha:fecha',
                    'fecha_vencimiento' => 'FechaVenc:fecha',
                    'nota_venta' => 'nvnumero:entero',
                    'centro_costo' => 'CentroDeCosto',
                    'condicion' => 'CondPago',
                    'glosa' => 'Glosa',
                    'neto' => 'NetoAfecto:decimal',
                    'exento' => 'NetoExento:decimal',
                    'iva' => 'IVA:decimal',
                    'total' => 'Total:decimal',
                    'enviado_sii' => 'FechaGenDTE:fecha',
                ],
                'filtro' => function (Builder $q, array $ctx, bool $ventana = true) {
                    if ($ventana) {
                        $q->where('Fecha', '>=', static::desdeHistoria());
                    }
                    // Sólo lo que la app entiende: venta electrónica. Las guías,
                    // los traslados y lo interno de inventario no son suyos.
                    $q->whereIn('Tipo', ['F', 'B', 'N']);
                    static::soloSusVendedores($q, 'CodVendedor', $ctx);
                },
            ],
            'factura_lineas' => [
                'titulo' => 'Detalle de facturas',
                'tabla' => 'softland.iw_gmovi',
                'clave' => ['Tipo', 'NroInt', 'Linea'],
                'campos' => [
                    'tipo' => 'Tipo',
                    'numero_interno' => 'NroInt:entero',
                    'linea' => 'Linea:decimal',
                    'producto' => 'CodProd',
                    'detalle' => 'DetProd',
                    'unidad' => 'CodUMed',
                    'cantidad' => 'CantFacturada:decimal',
                    // Ya en la moneda del documento: de aquí sale el `PrcItem`
                    // del DTE, y el SII comprueba que cuadre con el total.
                    'precio' => 'PreUniMB:decimal',
                    'descuento' => 'TotalDescMov:decimal',
                    'total' => 'TotLinea:decimal',
                    // A qué línea de la nota de venta corresponde. Es el enlace
                    // nativo, y lo que permite saber qué queda por facturar.
                    'nota_venta_linea' => 'nvCorrela:decimal',
                    // Y a qué línea de la factura devuelve, en una nota de crédito.
                    'devuelve_linea' => 'FactNumLin:decimal',
                ],
                // El alcance se cruza por **las dos** columnas de la clave. En
                // `iw_gsaen` el `NroInt` se repite entre tipos —hay una factura
                // 5 y una nota de crédito 5—, así que mirar sólo el número
                // dejaría ver líneas de documentos de otro vendedor.
                'filtro' => function (Builder $q, array $ctx, bool $ventana = true) {
                    $q->whereIn('Tipo', ['F', 'B', 'N']);
                    $q->whereExists(function ($s) use ($ctx, $ventana) {
                        $s->selectRaw('1')->from('softland.iw_gsaen AS cab')
                            ->whereColumn('cab.Tipo', 'iw_gmovi.Tipo')
                            ->whereColumn('cab.NroInt', 'iw_gmovi.NroInt');

                        if ($ventana) {
                            $s->where('cab.Fecha', '>=', static::desdeHistoria());
                        }

                        static::soloSusVendedores($s, 'cab.CodVendedor', $ctx);
                    });
                },
            ],
            /*
             * En qué quedó cada documento con el SII.
             *
             * Vive en `dte_doccab`, aparte de `iw_gsaen`: un documento puede
             * estar escrito en inventario y no haber viajado todavía. El
             * `TrackID` es el número con que el SII lo identifica, y `Motivo`
             * trae su veredicto en castellano —«DTE Recibido - Documento
             * Recibido por el SII. Datos Coinciden con los Registrados»—.
             *
             * Baja al teléfono para que la ficha diga «enviada» o «sin enviar»
             * sin señal. Preguntarle al SII sí la necesita, pero eso es otra
             * pregunta y sólo se hace cuando alguien la hace.
             */
            'dte_estado' => [
                'titulo' => 'Estado ante el SII',
                'tabla' => 'softland.dte_doccab',
                'clave' => ['TipoDTE', 'Folio'],
                'campos' => [
                    'tipo_sii' => 'TipoDTE:entero',
                    'folio' => 'Folio:entero',
                    'tipo' => 'Tipo',
                    'numero_interno' => 'NroInt:entero',
                    'track_id' => 'TrackID',
                    'enviado' => 'EnviadoSII:entero',
                    'aceptado' => 'AceptadoSII:entero',
                    'fecha_envio' => 'FechaEnvioSII:fecha',
                    'motivo' => 'Motivo',
                ],
                'filtro' => function (Builder $q, array $ctx, bool $ventana = true) {
                    $q->whereExists(function ($s) use ($ctx, $ventana) {
                        $s->selectRaw('1')->from('softland.iw_gsaen AS cab')
                            ->whereColumn('cab.Tipo', 'dte_doccab.Tipo')
                            ->whereColumn('cab.NroInt', 'dte_doccab.NroInt');

                        if ($ventana) {
                            $s->where('cab.Fecha', '>=', static::desdeHistoria());
                        }

                        static::soloSusVendedores($s, 'cab.CodVendedor', $ctx);
                    });
                },
            ],
            /*
             * Qué documento acredita o referencia cada factura y nota de crédito.
             *
             * Es la tabla donde **de verdad** se dice qué se acredita:
             * `CodRefSII` con el tipo del SII y `FolioRef` con el folio. No es
             * `AuxDocNum`, que parecía: 5.317 facturas de NETDOMAIN lo llevan
             * relleno con otra cosa, y cruzar por ahí emparejaba notas de
             * crédito con documentos que no tenían nada que ver.
             *
             * Baja al teléfono para que la ficha pueda decir «esta factura ya se
             * anuló con la nota de crédito 16» sin señal, y no ofrecer anular
             * dos veces lo mismo.
             */
            'factura_referencias' => [
                'titulo' => 'Referencias de facturas',
                'tabla' => 'softland.IW_GSaEn_RefDTE',
                'clave' => ['Tipo', 'NroInt', 'LineaRef'],
                'campos' => [
                    'tipo' => 'Tipo',
                    'numero_interno' => 'NroInt:entero',
                    'linea' => 'LineaRef:entero',
                    'tipo_sii_referido' => 'CodRefSII',
                    'folio_referido' => 'FolioRef',
                    'fecha_referida' => 'FechaRef:fecha',
                    'codigo' => 'CodRef',
                    'razon' => 'RazonRef',
                ],
                'filtro' => function (Builder $q, array $ctx, bool $ventana = true) {
                    $q->whereIn('Tipo', ['F', 'B', 'N']);
                    $q->whereExists(function ($s) use ($ctx, $ventana) {
                        $s->selectRaw('1')->from('softland.iw_gsaen AS cab')
                            ->whereColumn('cab.Tipo', 'IW_GSaEn_RefDTE.Tipo')
                            ->whereColumn('cab.NroInt', 'IW_GSaEn_RefDTE.NroInt');

                        if ($ventana) {
                            $s->where('cab.Fecha', '>=', static::desdeHistoria());
                        }

                        static::soloSusVendedores($s, 'cab.CodVendedor', $ctx);
                    });
                },
            ],
            /*
             * De qué línea de cotización salió cada línea de nota de venta.
             *
             * Es lo único del ciclo que Softland no guarda: `nwdetcot` no tiene
             * columna de cantidad consumida y `CtEstado` pasa a `V` con la
             * primera nota de venta, dé lo mismo si se convirtió entera o una
             * línea de ocho.
             *
             * Baja al teléfono porque el saldo tiene que verse **sin señal**: la
             * ficha de la cotización dice «quedan 1 de 8 líneas» y la lista la
             * marca como parcial, y las dos se leen en terreno. Son pocas filas
             * —una por línea convertida— y viajan con las marcas de creación,
             * que es lo que permite descartar un enlace cuyo número ya se
             * repartió a otro documento.
             */
            /*
             * Los atributos de la nota de venta: los campos que cada empresa
             * define por su cuenta en el ERP.
             *
             * **No hay ninguno garantizado.** INNOVAGES declara cuatro y
             * NETDOMAIN uno; otra empresa puede no declarar ninguno. Así que
             * aquí no se nombra ni se da por hecho ninguno: bajan los que haya,
             * y la pantalla dibuja lo que baje.
             *
             * El `Tipo` dice cómo se pide y dónde se guarda el valor:
             * **1** número y **2** sí/no van al texto libre, **3** es una fecha
             * y **4** una lista de opciones. Está comprobado mirando dónde
             * acaban los valores de los nueve atributos que hay definidos entre
             * las dos empresas.
             */
            'nv_atributos' => [
                'titulo' => 'Atributos de la nota de venta',
                'tabla' => 'softland.NW_NventaTTAtr',
                'clave' => ['CodTat'],
                'campos' => [
                    'codigo' => 'CodTat:entero',
                    'nombre' => 'NombreTipo',
                    'descripcion' => 'DescripcionTipo',
                    'tipo' => 'Tipo:entero',
                    'valor_defecto' => 'ValorDef',
                ],
                'etiqueta' => 'nombre',
                'filtro' => fn (Builder $q) => $q->where('IdMaestro', 4),
            ],
            'nv_atributo_opciones' => [
                'titulo' => 'Opciones de los atributos',
                'tabla' => 'softland.NW_NventaTVAtr',
                'clave' => ['CodTat', 'CodTAtE'],
                'campos' => [
                    'atributo' => 'CodTat:entero',
                    'codigo' => 'CodTAtE:entero',
                    'nombre' => 'DescripcionLista',
                ],
                'etiqueta' => 'nombre',
                'filtro' => fn (Builder $q) => $q->where('IdMaestro', 4),
            ],
            /*
             * El valor que lleva cada nota de venta.
             *
             * Sale de una vista nuestra (`ventas.nv_atributo_valor`) que une
             * las tres tablas donde Softland reparte los valores según el tipo.
             * El porqué está en la migración que la crea.
             */
            'nv_atributo_valores' => [
                'titulo' => 'Valores de los atributos',
                'tabla' => 'ventas.nv_atributo_valor',
                'clave' => ['nv_numero', 'cod'],
                'campos' => [
                    'nota_venta' => 'nv_numero:entero',
                    'atributo' => 'cod:entero',
                    'opcion' => 'opcion:entero',
                    'fecha' => 'fecha:fecha',
                    'texto' => 'texto',
                ],
                'filtro' => function (Builder $q, array $ctx, bool $ventana = true) {
                    if ($ventana) {
                        $q->where('nvFem', '>=', static::desdeHistoria());
                    }
                    static::soloSusVendedores($q, 'VenCod', $ctx);
                },
            ],
            /*
             * Los compromisos que la empresa declara en el ERP.
             *
             * La app **no interpreta estos códigos**: los lee y los muestra. Si
             * una empresa los llenó con porcentajes —como INNOVAGES, que no
             * tenía dónde poner el avance— salen porcentajes. Cuáles se ofrecen
             * al vendedor lo dice la configuración, no este maestro: los viejos
             * no se pueden borrar porque `nwtsegui` tiene clave foránea, y el
             * histórico tiene que seguir leyéndose.
             */
            'compromisos' => [
                'titulo' => 'Compromisos de seguimiento',
                'tabla' => 'softland.nwttcomp',
                'clave' => ['codcomp'],
                'campos' => ['codigo' => 'codcomp', 'nombre' => 'descomp'],
                'etiqueta' => 'nombre',
            ],
            /*
             * Las anotaciones de seguimiento.
             *
             * Bajan al teléfono desde que el panel cuenta compromisos: el panel
             * se calcula sobre IndexedDB y tiene que funcionar sin señal, así
             * que no puede preguntarle al servidor cuántos vencen hoy.
             */
            'seguimientos' => [
                'titulo' => 'Seguimientos',
                'tabla' => 'softland.nwtsegui',
                'clave' => ['CotNum', 'NroSeg'],
                'campos' => [
                    'cotizacion' => 'CotNum:entero',
                    'numero' => 'NroSeg:entero',
                    'fecha' => 'FecSeg:fecha',
                    'compromiso' => 'TipComp',
                    'contacto' => 'Contacto',
                    'proximo_contacto' => 'FecProComp:fecha',
                    'descripcion' => 'Descripcion',
                ],
                'filtro' => fn (Builder $q, array $ctx, bool $ventana = true) => $q->whereIn(
                    'CotNum', static::cabecerasVisibles('softland.nwcotiza', 'CotNum', 'CtFem', 'VenCod', $ctx, $ventana)
                ),
            ],
            /*
             * El avance de cada cotización, que es cosa nuestra.
             *
             * Baja la **historia entera** y no sólo el último: con ella el
             * teléfono puede decir «lleva seis semanas en 70 %», que es la
             * pregunta que de verdad importa.
             */
            'cotizacion_avance' => [
                'titulo' => 'Avance de cotizaciones',
                'tabla' => 'ventas.cotizacion_avance',
                'clave' => ['id'],
                'campos' => [
                    'id' => 'id:entero',
                    'cotizacion' => 'cot_num:entero',
                    'cotizacion_creada' => 'cot_creado_en:fecha',
                    'pct' => 'pct:entero',
                    'fecha' => 'created_at:fecha',
                ],
                'filtro' => fn (Builder $q, array $ctx, bool $ventana = true) => $q->whereIn(
                    'cot_num', static::cabecerasVisibles('softland.nwcotiza', 'CotNum', 'CtFem', 'VenCod', $ctx, $ventana)
                ),
            ],
            'linea_origen' => [
                'titulo' => 'Origen de las líneas',
                'tabla' => 'ventas.linea_origen',
                'clave' => ['nv_numero', 'nv_linea'],
                'campos' => [
                    'nota_venta' => 'nv_numero:entero',
                    'linea' => 'nv_linea:decimal',
                    'nota_venta_creada' => 'nv_creado_en:fecha',
                    'cotizacion' => 'cot_num:entero',
                    'cotizacion_linea' => 'cot_linea:decimal',
                    'cotizacion_creada' => 'cot_creado_en:fecha',
                    'cantidad' => 'cantidad:decimal',
                ],
                'filtro' => fn (Builder $q, array $ctx, bool $ventana = true) => $q->whereIn(
                    'cot_num', static::cabecerasVisibles('softland.nwcotiza', 'CotNum', 'CtFem', 'VenCod', $ctx, $ventana)
                ),
            ],
            'notas_venta' => [
                'titulo' => 'Notas de venta',
                'tabla' => 'softland.nw_nventa',
                'clave' => ['NVNumero'],
                'campos' => [
                    'numero' => 'NVNumero:entero',
                    'cotizacion' => 'CotNum:entero',
                    'cliente' => 'CodAux',
                    'contacto' => 'NomCon',
                    'vendedor' => 'VenCod',
                    'moneda' => 'CodMon',
                    'lista' => 'CodLista',
                    'condicion' => 'CveCod',
                    'centro_costo' => 'CodiCC',
                    'bodega' => 'CodBode',
                    'estado' => 'nvEstado',
                    'fecha' => 'nvFem:fecha',
                    'fecha_entrega' => 'nvFeEnt:fecha',
                    'fecha_aprobacion' => 'nvFeAprob:fecha',
                    'oc' => 'NumOC',
                    'observacion' => 'nvObser',
                    'neto' => 'nvNetoAfecto:decimal',
                    'exento' => 'nvNetoExento:decimal',
                    'descuento' => 'nvTotalDesc:decimal',
                    'flete' => 'nvValflete:decimal',
                    'embalaje' => 'nvValEmb:decimal',
                    'total' => 'nvMonto:decimal',
                    'creado' => 'FechaHoraCreacion:fecha',
                ],
                'filtro' => function (Builder $q, array $ctx, bool $ventana = true) {
                    if ($ventana) {
                        $q->where('nvFem', '>=', static::desdeHistoria());
                    }
                    static::soloSusVendedores($q, 'VenCod', $ctx);
                },
            ],
            'nota_venta_lineas' => [
                'titulo' => 'Detalle de notas de venta',
                'tabla' => 'softland.nw_detnv',
                'clave' => ['NVNumero', 'nvLinea'],
                'campos' => [
                    'nota_venta' => 'NVNumero:entero',
                    'linea' => 'nvLinea:decimal',
                    'producto' => 'CodProd',
                    'detalle' => 'DetProd',
                    'unidad' => 'CodUMed',
                    'cantidad' => 'nvCant:decimal',
                    'precio' => 'nvPrecio:decimal',
                    'equiv' => 'nvEquiv:decimal',
                    'descuento' => 'nvTotDesc:decimal',
                    'total' => 'nvTotLinea:decimal',
                    // El avance real de una NV se lee aquí, línea por línea: los
                    // flags del encabezado (`nvEstFact`, `nvEstDesp`) están en 0
                    // en las 800 filas de INNOVAGES, Softland no los usa.
                    'facturado' => 'nvCantFact:decimal',
                    'despachado' => 'nvCantDesp:decimal',
                ],
                'filtro' => fn (Builder $q, array $ctx, bool $ventana = true) => $q->whereIn(
                    'NVNumero', static::cabecerasVisibles('softland.nw_nventa', 'NVNumero', 'nvFem', 'VenCod', $ctx, $ventana)
                ),
            ],
        ];
    }

    /**
     * Hasta dónde atrás se lleva el vendedor sus documentos.
     *
     * ## La ventana y el alcance son dos cosas distintas
     *
     * El `filtro` de los cuatro maestros de documentos hace dos trabajos: uno
     * es el **alcance** —qué vendedores puede ver este usuario—, que es
     * permiso y no se negocia nunca; el otro es la **ventana** de doce meses,
     * que es sólo cuánto equipaje se lleva el teléfono.
     *
     * Por eso se pueden separar, y hay una pregunta donde hay que hacerlo:
     * buscar **un** documento por su número. La cotización 8000 es de 2024 y
     * ningún teléfono la tiene, pero es del vendedor que la pide y no hay
     * ninguna razón para negársela. Bajarse las 2.351 de la historia para eso
     * sí la habría: son once mil líneas y además ensuciarían el panel, donde
     * aparecerían como cotizaciones vencidas de hace dos años.
     *
     * Lo que **no** se toca al quitar la ventana es el alcance. Pedir sin
     * ventana sigue devolviendo 404 en el documento de otro vendedor.
     */
    public static function desdeHistoria(): string
    {
        return now()->subMonths(self::MESES_HISTORIA)->startOfDay()->format('Y-m-d H:i:s');
    }

    /**
     * Un vendedor ve lo suyo; un supervisor, lo de su gente; administración y
     * facturación, todo. `$ctx['vendedores'] === null` significa «sin límite».
     *
     * Si la lista viene vacía no se filtra por «ninguno»: se fuerza un IN vacío
     * que no devuelve nada. Un vendedor sin `ven_cod` no tiene documentos
     * atribuibles, y mostrarle los de todos sería peor que mostrarle ninguno.
     */
    protected static function soloSusVendedores(Builder $q, string $columna, array $ctx): void
    {
        // Sin contexto no se abre: que la clave falte significa que alguien
        // llamó al maestro por un camino que no sabe de permisos, y el valor
        // seguro ahí es «nada», no «todo».
        if (! array_key_exists('vendedores', $ctx)) {
            $q->whereRaw('1 = 0');

            return;
        }

        $vendedores = $ctx['vendedores'];
        if ($vendedores === null) {
            return; // administración y facturación ven la empresa entera
        }

        $q->whereIn($columna, $vendedores ?: ['\x00-ninguno']);
    }

    /** Subconsulta con los números de documento que este usuario puede ver. */
    protected static function cabecerasVisibles(string $tabla, string $pk, string $fecha, string $vendedor, array $ctx, bool $ventana = true): \Closure
    {
        return function ($q) use ($tabla, $pk, $fecha, $vendedor, $ctx, $ventana) {
            $q->select($pk)->from($tabla);

            if ($ventana) {
                $q->where($fecha, '>=', static::desdeHistoria());
            }

            static::soloSusVendedores($q, $vendedor, $ctx);
        };
    }

    public static function existe(string $recurso): bool
    {
        return array_key_exists($recurso, static::recursos());
    }

    protected function conn()
    {
        return DB::connection('softland');
    }

    /**
     * Resumen de los maestros: cuántas filas tiene cada uno hoy.
     *
     * `$solo` acota a una lista de nombres, conservando el orden declarado:
     * las dependencias importan —el detalle de una cotización no sirve sin su
     * cabecera— y ese orden es el de `recursos()`, no el de quien pregunta.
     */
    public function inventario(array $ctx = [], ?array $solo = null): array
    {
        $out = [];
        foreach (static::recursos() as $nombre => $def) {
            if ($solo !== null && ! in_array($nombre, $solo, true)) {
                continue;
            }

            $out[] = [
                'recurso' => $nombre,
                'titulo' => $def['titulo'],
                'total' => $this->consulta($def, $ctx)->count(),
                'incremental' => isset($def['fecha']),
            ];
        }

        return $out;
    }

    /**
     * Una página de un maestro.
     *
     * @param  string|null  $desde   ISO8601 de la última descarga; trae solo lo
     *                               cambiado desde entonces. La hora es **del
     *                               servidor**: el reloj del teléfono puede ir
     *                               corrido y perdería filas en silencio.
     * @param  string|null  $cursor  Última clave entregada, para seguir donde se
     *                               quedó. Las claves compuestas van unidas por «|».
     */
    public function pagina(string $recurso, ?string $desde = null, ?string $cursor = null, int $limite = self::LIMITE, array $ctx = []): array
    {
        $def = static::recursos()[$recurso];
        $limite = max(1, min($limite, self::LIMITE_MAX));

        $q = $this->consulta($def, $ctx);

        if ($desde && isset($def['fecha'])) {
            $q->where($def['fecha'], '>', $desde);
        }

        $this->aplicarCursor($q, $def['clave'], $cursor);

        foreach ($def['clave'] as $col) {
            $q->orderBy($col);
        }

        $filas = $q->limit($limite + 1)->get($this->columnas($def))->all();

        // Se pidió una fila de más solo para saber si queda algo detrás.
        $hayMas = count($filas) > $limite;
        if ($hayMas) {
            array_pop($filas);
        }

        $ultima = end($filas) ?: null;

        return [
            'recurso' => $recurso,
            'titulo' => $def['titulo'],
            'incremental' => isset($def['fecha']),
            'filas' => array_map(fn ($f) => $this->mapear($def, $f), $filas),
            // `total` es del maestro completo, sin `desde`: es contra este número
            // que el teléfono decide si se le quedaron filas borradas. Se cuenta
            // solo en la primera página — repetirlo en cada una sería un COUNT
            // completo por página y el número no cambia a mitad de descarga.
            'total' => $cursor ? null : $this->consulta($def, $ctx)->count(),
            'cursor' => $hayMas && $ultima ? $this->cursorDe($def['clave'], $ultima) : null,
            'hay_mas' => $hayMas,
        ];
    }

    /**
     * Una fila de un maestro, mapeada igual que en la descarga.
     *
     * El contexto va explícito porque los maestros con alcance por vendedor
     * (cotizaciones, notas de venta y sus detalles) se cierran sin él. Quien
     * pida una cotización sin decir de parte de quién no recibe nada, que es
     * lo correcto: el camino que no sabe de permisos falla cerrado.
     *
     * `$ventana = false` levanta el corte de doce meses —y sólo ese— para
     * poder buscar un documento viejo por su número. El alcance sigue puesto.
     */
    public function uno(string $recurso, array $donde, array $ctx = [], bool $ventana = true): ?array
    {
        $def = static::recursos()[$recurso];
        $fila = $this->consulta($def, $ctx, $ventana)->where($donde)->first($this->columnas($def));

        return $fila ? $this->mapear($def, $fila) : null;
    }

    /** Varias filas de un maestro, mapeadas igual que en la descarga. */
    public function varios(string $recurso, array $donde, array $ctx = [], bool $ventana = true): array
    {
        $def = static::recursos()[$recurso];
        $q = $this->consulta($def, $ctx, $ventana)->where($donde);
        foreach ($def['clave'] as $col) {
            $q->orderBy($col);
        }

        return $q->get($this->columnas($def))->map(fn ($f) => $this->mapear($def, $f))->all();
    }

    // ---------------------------------------------------------------- interno

    protected function consulta(array $def, array $ctx = [], bool $ventana = true): Builder
    {
        $q = $this->conn()->table($def['tabla']);

        if (isset($def['filtro'])) {
            ($def['filtro'])($q, $ctx, $ventana);
        }

        return $q;
    }

    /** Columnas SQL a pedir: las del mapa, sin el sufijo de tipo. */
    protected function columnas(array $def): array
    {
        $cols = [];
        foreach ($def['campos'] as $col) {
            $cols[] = explode(':', $col)[0];
        }

        return array_values(array_unique(array_merge($cols, $def['clave'])));
    }

    /**
     * Paginación por clave, no por OFFSET.
     *
     * Con OFFSET, una fila insertada a mitad de la descarga corre todas las
     * demás y se salta registros. Comparando contra la última clave entregada
     * eso no puede pasar, y además la consulta usa el índice de la PK.
     *
     * Para claves compuestas la condición es la lexicográfica:
     *   a > A  OR  (a = A AND b > B)
     */
    protected function aplicarCursor(Builder $q, array $clave, ?string $cursor): void
    {
        if ($cursor === null || $cursor === '') {
            return;
        }

        $partes = explode('|', $cursor);
        if (count($partes) !== count($clave)) {
            return; // cursor de otra versión: se ignora y se parte de cero
        }

        $q->where(function (Builder $w) use ($clave, $partes) {
            foreach ($clave as $i => $col) {
                $w->orWhere(function (Builder $x) use ($clave, $partes, $i, $col) {
                    for ($j = 0; $j < $i; $j++) {
                        $x->where($clave[$j], '=', $partes[$j]);
                    }
                    $x->where($col, '>', $partes[$i]);
                });
            }
        });
    }

    protected function cursorDe(array $clave, object $fila): string
    {
        return implode('|', array_map(fn ($c) => trim((string) ($fila->$c ?? '')), $clave));
    }

    /** Fila de Softland → fila para el teléfono, con los nombres y tipos de la app. */
    protected function mapear(array $def, object $fila): array
    {
        $out = [];
        foreach ($def['campos'] as $alias => $spec) {
            [$col, $tipo] = array_pad(explode(':', $spec, 2), 2, 'texto');
            $out[$alias] = $this->convertir($fila->$col ?? null, $tipo);
        }

        // Softland deja descripciones en blanco (la lista de precios «01» de
        // INNOVAGES). Una opción sin nombre no se puede elegir a ciegas: se cae
        // al código, que al menos identifica la fila.
        $etiqueta = $def['etiqueta'] ?? null;
        if ($etiqueta && ($out[$etiqueta] ?? '') === '' && isset($out['codigo'])) {
            $out[$etiqueta] = $out['codigo'];
        }

        return $out;
    }

    protected function convertir($valor, string $tipo)
    {
        if ($valor === null) {
            return match ($tipo) {
                'entero' => 0,
                'decimal' => 0.0,
                'sn', 'flag' => false,
                'fecha' => null,
                default => '',
            };
        }

        return match ($tipo) {
            'entero' => (int) $valor,
            // Redondeado a la fuerza: los totales de Softland vienen con ruido de
            // coma flotante («exento: -4.6E-10» en una cotización real) y eso, sin
            // redondear, se dibuja en la pantalla del vendedor tal cual.
            'decimal' => round((float) $valor, 4),
            // varchar(1) con 'S'/'N': el estilo clásico de Softland.
            'sn' => in_array(strtoupper(trim((string) $valor)), ['S', '1', 'T'], true),
            // int con 0/-1: el estilo de las tablas de Existencias. -1 es verdadero.
            'flag' => ((int) $valor) !== 0,
            'fecha' => $this->fecha($valor),
            default => $this->texto($valor),
        };
    }

    /**
     * Los datos vienen con basura de digitación: espacios de relleno del `char`,
     * tabuladores pegados al copiar desde Excel y saltos de línea sueltos. Sin
     * esto, buscar «MAURICIO ROJAS» no encuentra «MAURICIO ROJAS\t».
     */
    protected function texto($valor): string
    {
        $v = (string) $valor;
        // El `/u` falla y devuelve null si la fila trae un byte que no es UTF-8
        // válido. Pasa con datos viejos cargados desde planillas: en ese caso se
        // limpia sin modo unicode antes que perder el dato.
        return trim(preg_replace('/\s+/u', ' ', $v) ?? preg_replace('/\s+/', ' ', $v));
    }

    protected function fecha($valor): ?string
    {
        $v = trim((string) $valor);

        return $v === '' ? null : str_replace(' ', 'T', substr($v, 0, 19));
    }
}
