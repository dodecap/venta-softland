<?php

namespace App\Services\Softland;

use Illuminate\Support\Facades\DB;

/**
 * ¿Sirve esta base de Softland para esta app?
 *
 * La pregunta no es «¿es una base Softland?» —eso ya lo contesta /setup
 * buscando el usuario administrador— sino «¿tiene lo que la app usa?». Cada
 * empresa corre la versión de Softland que le tocó, y entre versiones aparecen
 * y desaparecen columnas. Sin esta comprobación, una base a la que le falte
 * `dte_siicaf` se instala igual y el problema sale a la luz el día que alguien
 * intenta facturar, que es el peor día posible.
 *
 * Se mira **antes de guardar la conexión**, contra la conexión de prueba, y
 * también a mano con `php artisan ventas:compatibilidad`.
 *
 * Dos decisiones que explican la forma de todo esto:
 *
 * **Lo que la app lee no se escribe aquí, se deduce.** `Maestros::recursos()`
 * ya declara tabla, clave y columnas de los treinta maestros; repetir esa lista
 * sería tener dos verdades esperando a diferenciarse. Un maestro nuevo queda
 * comprobado sin tocar este archivo, que es la misma regla del catálogo: «un
 * maestro nuevo es un arreglo más».
 *
 * **Lo que falta no siempre es fatal.** Una base sin las tablas del DTE sirve
 * perfectamente para cotizar y vender; lo que no puede es facturar. Así que
 * cada cosa cuelga de un grupo, el grupo dice qué se pierde, y sólo los
 * esenciales impiden instalar. Un «no se puede instalar» ante la falta de
 * `iw_encpicking` sería mentira.
 */
class Compatibilidad
{
    /**
     * Qué se pierde cuando falla algo de cada grupo, y si impide instalar.
     *
     * El orden es el del informe: primero lo que tumba la instalación.
     */
    private const GRUPOS = [
        'nucleo' => ['Entrar y leer la empresa', true],
        'venta' => ['Cotizar y vender', true],
        'catalogo' => ['Algunos maestros del catálogo', false],
        'seguimiento' => ['El seguimiento de cotizaciones', false],
        'factura' => ['Facturar', false],
        'dte' => ['Emitir documentos tributarios electrónicos', false],
    ];

    /**
     * Las tablas que la app usa y que **no** son maestros del catálogo: se leen
     * o se escriben desde el código, una a una.
     *
     * Las del catálogo no están aquí a propósito — salen de `Maestros`.
     *
     * @var array<string, array{0: string, 1: array<int, string>}>  tabla => [grupo, columnas]
     */
    private const FUERA_DEL_CATALOGO = [
        // Quién entra y con qué contraseña. Sin esto no hay ni login.
        'wisusuarios' => ['nucleo', ['Usuario', 'PassWord', 'Nombre', 'Rut', 'eMail', 'Bloqueado']],
        // La identidad de la empresa que va impresa en cada documento. Los
        // nombres son los del ERP: `NomB` es la razón social y `Dire` el
        // domicilio tributario, que no es lo mismo que la oficina comercial.
        'soempre' => ['nucleo', ['RutE', 'NomB', 'Giro', 'Dire', 'Comu', 'Ciud', 'Fono',
            'EMailDTE', 'SitioWEB', 'DTENumeroResol', 'DTEFechaResol']],
        // Los permisos de Softland, que se conceden por perfil y por usuario y
        // se suman. `Formulario` y `Control` son el par que los nombra.
        'wisperfilusuario' => ['nucleo', ['Sistema', 'Perfil', 'Usuario']],
        'wisrestperfil' => ['nucleo', ['Sistema', 'Perfil', 'Formulario', 'Control']],
        'wisrestusuario' => ['nucleo', ['Sistema', 'Usuario', 'Formulario', 'Control']],

        // Los parámetros del módulo de ventas. De aquí sale, entre otras cosas,
        // si la nota de venta nace pendiente de aprobación.
        'nwparam' => ['venta', ['CheckApruebaNv']],
        // El IVA de la cotización y el de la nota de venta, cada uno en su tabla.
        'NWCtImpto' => ['venta', ['CotNum', 'codimpto', 'valpctIni', 'afectoImpto', 'Impto']],
        'NW_Impto' => ['venta', ['nvNumero', 'codimpto', 'valpctIni', 'afectoImpto', 'Impto']],
        // Los valores de los atributos de la nota de venta, uno por tipo de
        // dato. `Codigo` es el número de la nota de venta, en texto.
        'NW_NventaTVAtrV' => ['venta', ['CodTat', 'IdMaestro', 'Codigo', 'Valor']],
        'NW_NventaTVAtrT' => ['venta', ['CodTat', 'IdMaestro', 'Codigo', 'CodTAtE']],
        'NW_NventaTVAtrF' => ['venta', ['CodTat', 'IdMaestro', 'Codigo', 'ValorFecha']],
        // La UF, para los documentos que no van en pesos.
        'so_uf' => ['venta', ['Fecha', 'Valor']],

        // Si una nota de venta ya tiene picking, no se corrige.
        'iw_encpicking' => ['factura', ['NroPicking', 'nvnumero']],

        // Los folios que el SII autorizó, con su llave privada dentro.
        'dte_siicaf' => ['dte', ['DocCod', 'FolioD', 'FolioH', 'CAFXML', 'RSASK', 'RUT', 'Fecha']],
        // El XML firmado tal como salió, guardado.
        'dte_archivos' => ['dte', ['TipoDTE', 'Folio', 'TipoXML', 'Archivo']],
    ];

    /**
     * Las columnas que la app **escribe** y que el catálogo no nombra porque no
     * las lee nunca.
     *
     * Ésta sí es una lista a mano, y no hay forma de que no lo sea: nacen de
     * los arreglos de `Ventas` y `Facturacion`, que son código. La regla para
     * mantenerla: si una escritura nueva estrena columna, se añade aquí. Lo que
     * pasa si se olvida es acotado —la comprobación deja de mirar esa columna—
     * y nunca al revés: aquí no se puede inventar una columna que no exista,
     * porque la primera base contra la que esto corre lo diría.
     *
     * @var array<string, array<int, string>>
     */
    private const ESCRITURA = [
        'nwcotiza' => [
            'CtFeEnt', 'numOC', 'CtSubTotal', 'CtPorcDesc01', 'CtDscto01', 'CtTotalDesc',
            'CtNetoAfecto', 'CtNetoExento', 'Sistema', 'Proceso', 'UsuarioGeneraDocto',
            'FechaHoraCreacion',
        ],
        'nwdetcot' => [
            'CtFecCompr', 'CtEquiv', 'CtSubTotal', 'CtDPorcDesc01', 'CtDDescto01',
            'CtTotDesc', 'CantUVta', 'CantidadKit', 'PorcIncidenciaKit',
        ],
        'nw_nventa' => [
            'nvFeEnt', 'NumOC', 'nvSubTotal', 'nvPorcDesc01', 'nvDescto01', 'nvTotalDesc',
            'nvNetoAfecto', 'nvNetoExento', 'NumReq', 'FechaUlMod', 'nvFeAprob',
            'Sistema', 'Proceso', 'UsuarioGeneraDocto', 'FechaHoraCreacion',
        ],
        'nw_detnv' => [
            'nvFecCompr', 'nvEquiv', 'nvSubTotal', 'nvDPorcDesc01', 'nvDDescto01',
            'nvTotDesc', 'CantUVta', 'CantidadKit', 'PorcIncidenciaKit',
        ],
        'nwtsegui' => ['CotNum', 'NroSeg', 'FecSeg', 'HorSeg', 'FecProComp', 'TipComp', 'Contacto', 'Descripcion'],
        'cwtauxi' => [
            'ActAux', 'Bloqueado', 'ClaCli', 'ClaDis', 'ClaEmp', 'ClaOtr', 'ClaPro', 'ClaSoc',
            'ClienteDesde', 'FechaUlMod', 'Sistema', 'Proceso', 'Usuario',
        ],
        'cwtaxco' => ['CodAuc', 'NomCon', 'CarCon', 'Email', 'FonCon', 'Sistema', 'Proceso', 'Usuario', 'FechaUlMod'],
        'iw_gsaen' => [
            'AuxDocfec', 'AuxDocNum', 'AuxTipo', 'CanCod', 'CentroDeCosto', 'CodBode',
            'CodMoneda', 'CodVendedor', 'Concepto', 'CondPago', 'Descto01', 'Equivalencia',
            'Estado', 'FactorCostoImportacion', 'Fecha', 'FechaVenc', 'FecHoraCreacion',
            'FmaPago', 'Folio', 'Glosa', 'IVA', 'NetoAfecto', 'NetoExento', 'NomContacto',
            'NroInt', 'nvnumero', 'PorcDesc01', 'Proceso', 'Sistema', 'SubTipDocRef',
            'SubTipoDocto', 'SubTotal', 'TipDocRef', 'Tipo', 'TipoServicioSII', 'TipoTrans',
            'Total', 'TotalDesc', 'TtdCod', 'Usuario',
        ],
        'iw_gmovi' => [
            'Actualizado', 'AuxTipo', 'CantFacturada', 'CantFactUVta', 'CodiCC', 'DescMov01',
            'Equivalencia', 'FactNumLin', 'Fecha', 'PorcDescMov01', 'PreUniMB', 'TipoDestino',
            'TipoOrigen', 'TotalDescMov', 'TotLinea',
        ],
        'IW_GSaEn_RefDTE' => ['Tipo', 'NroInt', 'LineaRef', 'CodRef', 'CodRefSII', 'FechaRef', 'FolioRef', 'Glosa', 'RazonRef'],
        'dte_doccab' => ['TipoDTE', 'Folio', 'Tipo', 'NroInt', 'FchEmis', 'FechaGenDTE', 'RUTEmisor', 'RUTRecep', 'AceptadoSII', 'Motivo'],
    ];

    /**
     * El procedimiento con el que Softland reparte los folios del DTE.
     *
     * No se sustituye por una cuenta propia: hacerlo sería disputarle el número
     * al ERP. Si no está, la app no puede facturar, y hay que decirlo antes.
     */
    private const PROCEDIMIENTO_FOLIOS = 'DTE_pdblEntregaFolioDTE';

    public function __construct(private string $conexion = 'softland') {}

    /**
     * El informe entero, en orden de gravedad.
     *
     * @return array{
     *     ok: bool,
     *     esenciales: bool,
     *     filas: array<int, array{ok: bool, grupo: string, que: string, detalle: string, esencial: bool}>,
     *     limita: array<int, string>
     * }
     */
    public function informe(): array
    {
        $tablas = $this->tablasDeLaBase();
        $columnas = $this->columnasDeLaBase();
        $procedimientos = $this->procedimientosDeLaBase();

        $filas = [];

        foreach ($this->requisitos() as $tabla => $req) {
            [$grupo, $pedidas] = $req;
            $filas[] = $this->comprobarTabla($tabla, $grupo, $pedidas, $tablas, $columnas);
        }

        $filas[] = $this->comprobarProcedimiento($procedimientos);

        usort($filas, fn ($a, $b) => [$a['ok'], $this->orden($a['grupo'])] <=> [$b['ok'], $this->orden($b['grupo'])]);

        $esenciales = true;
        $limita = [];

        foreach ($filas as $f) {
            if ($f['ok']) {
                continue;
            }

            $f['esencial'] and $esenciales = false;
            $limita[$f['grupo']] = self::GRUPOS[$f['grupo']][0];
        }

        return [
            'ok' => $limita === [],
            'esenciales' => $esenciales,
            'filas' => $filas,
            'limita' => array_values($limita),
        ];
    }

    /** ¿Se puede instalar sobre esta base? */
    public function sirve(): bool
    {
        return $this->informe()['esenciales'];
    }

    /**
     * Tabla => [grupo, columnas], con los maestros deducidos del catálogo y lo
     * demás escrito arriba.
     *
     * Las claves se comparan sin distinguir mayúsculas porque el código las
     * escribe de las dos formas —`nw_nventa` y `NW_NventaTVAtr`— y SQL Server,
     * con la intercalación de Softland, tampoco distingue.
     *
     * Es público para poder mirarlo sin una base delante: lo que hay que poder
     * comprobar en una prueba es que la deducción del catálogo sale bien, y eso
     * no necesita SQL Server.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public function requisitos(): array
    {
        $req = [];

        foreach (Maestros::recursos() as $r) {
            [$esquema, $tabla] = array_pad(explode('.', $r['tabla'], 2), 2, null);

            // `ventas.*` es nuestro: lo crean las migraciones, no Softland.
            if ($tabla === null || strtolower($esquema) !== 'softland') {
                continue;
            }

            $columnas = array_map(
                fn ($c) => explode(':', $c, 2)[0],
                array_values($r['campos'] ?? [])
            );

            $req[$tabla] = [
                $this->grupoDe($tabla),
                array_values(array_unique(array_merge($r['clave'] ?? [], $columnas))),
            ];
        }

        foreach (self::FUERA_DEL_CATALOGO as $tabla => $fila) {
            $req[$tabla] = $fila;
        }

        // Lo que se escribe se suma a lo que se lee, sobre la misma tabla.
        foreach (self::ESCRITURA as $tabla => $columnas) {
            $clave = $this->mismaClave($req, $tabla) ?? $tabla;
            $grupo = $req[$clave][0] ?? $this->grupoDe($tabla);
            $req[$clave] = [$grupo, array_values(array_unique(array_merge($req[$clave][1] ?? [], $columnas)))];
        }

        return $req;
    }

    /** La clave con la que ya está guardada esta tabla, si está con otra caja. */
    private function mismaClave(array $req, string $tabla): ?string
    {
        foreach (array_keys($req) as $k) {
            if (strcasecmp($k, $tabla) === 0) {
                return $k;
            }
        }

        return null;
    }

    /**
     * A qué grupo pertenece una tabla, por su prefijo.
     *
     * Softland nombra sus tablas por módulo —`nw` ventas, `iw` inventario y
     * facturación, `dte` el documento electrónico, `cw` los maestros comunes—,
     * así que el prefijo dice de qué parte de la app es cada una sin tener que
     * escribir la lista.
     */
    /** Qué se pierde con cada grupo, y si impide instalar. */
    public static function grupos(): array
    {
        return self::GRUPOS;
    }

    private function grupoDe(string $tabla): string
    {
        $t = strtolower($tabla);

        return match (true) {
            str_starts_with($t, 'dte_') => 'dte',
            str_starts_with($t, 'iw_gsaen'), str_starts_with($t, 'iw_gmovi') => 'factura',
            in_array($t, ['nwtsegui', 'nwttcomp'], true) => 'seguimiento',
            in_array($t, self::DEL_FLUJO, true) => 'venta',
            default => 'catalogo',
        };
    }

    /**
     * Los maestros sin los que no se puede escribir un documento, y por tanto
     * son tan imprescindibles como las tablas del documento mismo: en la
     * cabecera de una cotización van el cliente, el vendedor, la moneda, la
     * condición de venta, la lista de precios y la bodega, y ninguno admite
     * quedarse vacío.
     *
     * El resto del catálogo —comunas, giros, cargos, motivos de pérdida— llena
     * desplegables: si falta uno, lo que se pierde es ese desplegable, y eso no
     * justifica negarse a instalar.
     */
    private const DEL_FLUJO = [
        'nwcotiza', 'nwdetcot', 'nw_nventa', 'nw_detnv',
        'cwtauxi', 'cwtvend', 'iw_tprod', 'cwtmone', 'cwtconv', 'iw_tbode',
        'iw_tlispre', 'iw_tumed',
    ];

    private function orden(string $grupo): int
    {
        return array_search($grupo, array_keys(self::GRUPOS), true) ?: 0;
    }

    /**
     * @param  array<string, true>  $tablas
     * @param  array<string, array<string, true>>  $columnas
     */
    private function comprobarTabla(string $tabla, string $grupo, array $pedidas, array $tablas, array $columnas): array
    {
        $llave = strtolower($tabla);
        $esencial = self::GRUPOS[$grupo][1];

        if (! isset($tablas[$llave])) {
            return [
                'ok' => false,
                'grupo' => $grupo,
                'que' => 'softland.'.$tabla,
                'detalle' => 'La tabla no existe en esta base.',
                'esencial' => $esencial,
            ];
        }

        $faltan = [];

        foreach ($pedidas as $c) {
            isset($columnas[$llave][strtolower($c)]) or $faltan[] = $c;
        }

        return [
            'ok' => $faltan === [],
            'grupo' => $grupo,
            'que' => 'softland.'.$tabla,
            'detalle' => $faltan === []
                ? count($pedidas).' columnas, todas presentes.'
                : 'Faltan '.count($faltan).' de '.count($pedidas).' columnas: '.implode(', ', $faltan).'.',
            'esencial' => $esencial,
        ];
    }

    /** @param  array<string, true>  $procedimientos */
    private function comprobarProcedimiento(array $procedimientos): array
    {
        $hay = isset($procedimientos[strtolower(self::PROCEDIMIENTO_FOLIOS)]);

        return [
            'ok' => $hay,
            'grupo' => 'dte',
            'que' => 'softland.'.self::PROCEDIMIENTO_FOLIOS,
            'detalle' => $hay
                ? 'El repartidor de folios de Softland está.'
                : 'No está el procedimiento que reparte los folios del SII, y la app no reparte folios por su cuenta.',
            'esencial' => false,
        ];
    }

    /** @return array<string, true> */
    private function tablasDeLaBase(): array
    {
        $filas = DB::connection($this->conexion)->select(
            'SELECT TABLE_NAME AS n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?',
            ['softland']
        );

        return $this->indice($filas, fn ($f) => $f->n);
    }

    /** @return array<string, array<string, true>> */
    private function columnasDeLaBase(): array
    {
        $filas = DB::connection($this->conexion)->select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ?',
            ['softland']
        );

        $mapa = [];

        foreach ($filas as $f) {
            $mapa[strtolower($f->t)][strtolower($f->c)] = true;
        }

        return $mapa;
    }

    /** @return array<string, true> */
    private function procedimientosDeLaBase(): array
    {
        $filas = DB::connection($this->conexion)->select(
            'SELECT ROUTINE_NAME AS n FROM INFORMATION_SCHEMA.ROUTINES '
            .'WHERE ROUTINE_SCHEMA = ? AND ROUTINE_TYPE = ?',
            ['softland', 'PROCEDURE']
        );

        return $this->indice($filas, fn ($f) => $f->n);
    }

    private function indice(array $filas, callable $de): array
    {
        $mapa = [];

        foreach ($filas as $f) {
            $mapa[strtolower($de($f))] = true;
        }

        return $mapa;
    }
}
