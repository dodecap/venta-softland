<?php

namespace App\Services\Sii;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Del texto del SII a los códigos de Softland.
 *
 * El padrón del SII habla en castellano —«LOS ANGELES», «VENTA AL POR MENOR DE
 * COMPUTADORES…»— y `cwtauxi` guarda claves foráneas de seis y siete
 * caracteres. Traducir es todo el trabajo del alta automática, y es el único
 * sitio donde se hace.
 *
 * Tres campos, tres problemas distintos:
 *
 * ## La comuna: calza, con una lista corta de excepciones
 *
 * Los 347 nombres de comuna del padrón vigente se contrastaron contra las 352
 * de `cwtcomu`: **calzan 331**, que son el **98,48 %** de los 3.604.762
 * domicilios vigentes. De las 16 que no, once son la misma comuna escrita de
 * otra manera y están abajo en {@see self::COMUNAS}; «Sin Comuna» es un
 * marcador del padrón, no un sitio; y Cholchol sencillamente no está en
 * Softland. Para ésa se devuelve `null` y la elige una persona.
 *
 * ## Los duplicados a mano, que son la trampa
 *
 * `cwtcomu` no es sólo la lista oficial: tiene ocho filas añadidas a mano, con
 * código inventado y el nombre mal escrito —`CPN` «CONCPECION», `VITACUR`
 * «VITAVURA», `PUDAHUE` «PDH», `ESTACIO` «ESTACION CENTRAL»—, y varias
 * conviven con la oficial. Estación Central está dos veces: `13106` y
 * `ESTACIO`.
 *
 * Por eso, cuando dos filas se llaman igual, **gana la del código numérico**,
 * que es el del INE. Sin esa regla los clientes nuevos se repartirían entre la
 * comuna buena y su duplicado, y los informes de Softland que agrupan por
 * comuna dejarían de sumar.
 *
 * ## La ciudad: falta un tercio de las veces
 *
 * El SII la manda vacía en 18 de cada 53 fichas, y cuando la manda la trae
 * **recortada en 15 caracteres** —«ESTACIÓN CENTRA», «SAN PEDRO DE LA»—, así
 * que ese recorte ya no calza con nada. No es cosa de la API: el padrón la
 * guarda así.
 *
 * Cuando no hay ciudad se prueba con **el nombre de la comuna**, que en Chile
 * suele ser el mismo y así está puesto en Softland (comuna «Los Angeles»,
 * ciudad `LANGE` «Los Angeles»). Y como hay nombres de ciudad repetidos en
 * varias regiones, se desempata con la **región de la comuna ya resuelta**;
 * si sigue habiendo dos, se devuelve `null` en vez de elegir a cara o cruz.
 *
 * ## El giro: por código, nunca por texto
 *
 * Ver {@see self::giro()}. Es el que más cuidado pide y el que menos código
 * tiene.
 */
class Traduccion
{
    private const CONN = 'softland';

    /**
     * La comuna del padrón escrita como la escribe Softland.
     *
     * Las claves ya vienen normalizadas por {@see self::llave()}, así que aquí
     * no hay tildes ni guiones. «TIL-TIL» y «OHIGGINS» no están porque la
     * normalización sola ya los resuelve contra «Tiltil» y «O'Higgins».
     *
     * Tres de éstas son faltas de ortografía de Softland, no del SII —«Ista de
     * Pascua», «Quelén», «Treguaco»—, y se respetan: corregirlas es editar un
     * maestro del ERP, que es otra conversación.
     */
    public const COMUNAS = [
        'EST CENTRAL' => 'ESTACION CENTRAL',
        'AYSEN' => 'AISEN',
        'SAN FRANCISCO DE MOSTAZAL' => 'MOSTAZAL',
        'ISLA DE PASCUA' => 'ISTA DE PASCUA',
        'MARCHIGUE' => 'MARCHIHUE',
        'HUALAIHUE' => 'HUALAHUE',
        'PAIHUANO' => 'PAIGUANO',
        'QUEILEN' => 'QUELEN',
        'TREHUACO' => 'TREGUACO',
        'CABO DE HORNOS' => 'CABO DE HORNOSEXNAVARINO',
        'ALTO BIOBIO' => 'ALTO BIO BIO',
    ];

    /** Lo que el padrón pone cuando no hay comuna. No es un sitio. */
    private const SIN_COMUNA = 'SIN COMUNA';

    /** @var array<string, array{cod: string, nombre: string, region: ?int}>|null */
    private ?array $comunas = null;

    /** @var array<string, list<array{cod: string, region: ?int}>>|null */
    private ?array $ciudades = null;

    /** @var array<string, string>|null */
    private ?array $giros = null;

    public function __construct(private readonly ?string $base = null) {}

    /**
     * Los tres códigos de una ficha del SII, tal como la devuelve la API.
     *
     * @return array{comuna: ?string, ciudad: ?string, giro: ?string}
     */
    public function ficha(array $sii): array
    {
        $comuna = $this->comuna($sii['comuna'] ?? null);

        return [
            'comuna' => $comuna,
            'ciudad' => $this->ciudad($sii['ciudad'] ?? null, $comuna),
            'giro' => $this->giro($sii['giro_codigo'] ?? null),
        ];
    }

    /** El `ComCod` de `cwtcomu`, o `null` si esa comuna no está en Softland. */
    public function comuna(?string $texto): ?string
    {
        $llave = self::llave($texto);

        if ($llave === '' || $llave === self::SIN_COMUNA) {
            return null;
        }

        $llave = self::COMUNAS[$llave] ?? $llave;

        return $this->comunas()[$llave]['cod'] ?? null;
    }

    /**
     * El `CiuCod` de `cwtciud`.
     *
     * @param  ?string  $comunaCod  el `ComCod` ya resuelto: aporta la región
     *                              para desempatar y el nombre de reserva
     */
    public function ciudad(?string $texto, ?string $comunaCod = null): ?string
    {
        $region = $this->regionDeComuna($comunaCod);

        return $this->buscarCiudad($texto, $region)
            ?? $this->buscarCiudad($this->nombreDeComuna($comunaCod), $region);
    }

    /**
     * El `GirCod` de `cwtgiro` que corresponde a un ACTECO del SII.
     *
     * **Por código y nunca por texto.** El padrón reescribe descripciones de
     * una tanda a otra sin tocar el código, y una búsqueda por texto que deja
     * de encontrar no falla: acierta otra cosa, y esa otra cosa se imprime en
     * el `GiroRecep` del DTE.
     *
     * Y **nunca contra `sii_tacteco`**, que es el catálogo que trae Softland:
     * se comprobó que 696 de sus 698 códigos figuran como `ActEcoAntigua` en
     * `dte_siicodigosactecohomologados`, o sea que es la lista anterior a la
     * renumeración del SII. Las dos listas comparten números con significados
     * distintos: `702000` es «Corredores de propiedades» en la vieja y
     * «Actividades de consultoría de gestión» en la nueva.
     *
     * Primero manda `ventas.giro_sii`, que es donde se apunta el acteco a un
     * giro histórico; si no hay nada dicho, vale el acteco como `GirCod`.
     */
    public function giro(?string $acteco): ?string
    {
        $acteco = self::acteco($acteco);

        if ($acteco === null) {
            return null;
        }

        return $this->giros()[$acteco] ?? null;
    }

    /**
     * El catálogo ACTECO vigente: 674 códigos de seis dígitos.
     *
     * Sale de `resources/sii/actecos.tsv`, que se extrajo del padrón
     * `PUB_NOM_ACTECOS` del SII. Lo usan la carga de `cwtgiro` y
     * `ventas:verifica-sii`; la traducción de arriba no lo necesita.
     *
     * @return array<string, string> código de seis dígitos => descripción
     */
    public static function catalogo(): array
    {
        static $catalogo = null;

        if ($catalogo !== null) {
            return $catalogo;
        }

        $catalogo = [];
        $ruta = base_path('resources/sii/actecos.tsv');

        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            [$codigo, $descripcion] = array_pad(explode("\t", $linea, 2), 2, '');
            $catalogo[$codigo] = $descripcion;
        }

        return $catalogo;
    }

    /**
     * Seis dígitos, con su cero delante.
     *
     * Hay **94 actecos que empiezan por cero**. Si alguna vez llega como número
     * en vez de como texto —un `json_decode` distinto, otra versión de la
     * API—, `011101` se habría convertido en `11101`. Rellenar por la
     * izquierda lo devuelve a su sitio, y además es correcto: la lista nueva
     * escribe ese código con el cero.
     */
    public static function acteco(string|int|null $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor);

        if ($digitos === '' || strlen($digitos) > 6) {
            return null;
        }

        return str_pad($digitos, 6, '0', STR_PAD_LEFT);
    }

    /**
     * El texto reducido a lo comparable: mayúsculas, sin tildes y sin nada que
     * no sea letra, número o espacio.
     *
     * Quitar la puntuación en vez de cambiarla por un espacio es a propósito, y
     * resuelve dos casos sin necesidad de excepción: «TIL-TIL» contra «Tiltil»
     * y «OHIGGINS» contra «O'Higgins».
     */
    public static function llave(?string $texto): string
    {
        $texto = strtoupper(Str::ascii((string) $texto));
        $texto = preg_replace('/[^A-Z0-9 ]+/', '', $texto);

        return trim(preg_replace('/\s+/', ' ', $texto));
    }

    // ---------------------------------------------------------------- interno

    private function buscarCiudad(?string $texto, ?int $region): ?string
    {
        $llave = self::llave($texto);
        $filas = $llave === '' ? [] : ($this->ciudades()[$llave] ?? []);

        if ($filas === []) {
            return null;
        }

        if ($region !== null) {
            foreach ($filas as $fila) {
                if ($fila['region'] === $region) {
                    return $fila['cod'];
                }
            }
        }

        // El mismo nombre en dos regiones y sin forma de saber cuál: antes de
        // elegir a cara o cruz, que lo elija el vendedor.
        return count($filas) === 1 ? $filas[0]['cod'] : null;
    }

    private function regionDeComuna(?string $codigo): ?int
    {
        return $this->porCodigo($codigo)['region'] ?? null;
    }

    private function nombreDeComuna(?string $codigo): ?string
    {
        return $this->porCodigo($codigo)['nombre'] ?? null;
    }

    /** @return array{cod: string, nombre: string, region: ?int}|null */
    private function porCodigo(?string $codigo): ?array
    {
        if ($codigo === null) {
            return null;
        }

        foreach ($this->comunas() as $comuna) {
            if ($comuna['cod'] === $codigo) {
                return $comuna;
            }
        }

        return null;
    }

    /** `id_Region` en 0 es «no se sabe», y no sirve para desempatar. */
    private static function region(mixed $valor): ?int
    {
        $region = (int) $valor;

        return $region > 0 ? $region : null;
    }

    /** @return array<string, array{cod: string, region: ?int}> */
    private function comunas(): array
    {
        if ($this->comunas !== null) {
            return $this->comunas;
        }

        $this->comunas = [];

        foreach (DB::connection(self::CONN)->table($this->califica('cwtcomu'))->get() as $fila) {
            $llave = self::llave($fila->ComDes);
            $codigo = trim((string) $fila->ComCod);

            if ($llave === '' || $codigo === '') {
                continue;
            }

            // Ante dos filas con el mismo nombre gana la oficial, que es la del
            // código numérico del INE. Las otras las añadió alguien a mano.
            $anterior = $this->comunas[$llave] ?? null;

            if ($anterior !== null && ctype_digit($anterior['cod'])) {
                continue;
            }

            $this->comunas[$llave] = [
                'cod' => $codigo,
                'nombre' => (string) $fila->ComDes,
                'region' => self::region($fila->id_Region),
            ];
        }

        return $this->comunas;
    }

    /** @return array<string, list<array{cod: string, region: ?int}>> */
    private function ciudades(): array
    {
        if ($this->ciudades !== null) {
            return $this->ciudades;
        }

        $this->ciudades = [];

        foreach (DB::connection(self::CONN)->table($this->califica('cwtciud'))->get() as $fila) {
            $llave = self::llave($fila->CiuDes);
            $codigo = trim((string) $fila->CiuCod);

            if ($llave === '' || $codigo === '') {
                continue;
            }

            $this->ciudades[$llave][] = [
                'cod' => $codigo,
                'region' => self::region($fila->id_Region),
            ];
        }

        return $this->ciudades;
    }

    /**
     * ACTECO => `GirCod`, con `ventas.giro_sii` por encima de `cwtgiro`.
     *
     * @return array<string, string>
     */
    private function giros(): array
    {
        if ($this->giros !== null) {
            return $this->giros;
        }

        $this->giros = [];

        // `cwtgiro` es pequeña —2.009 filas— y sólo interesan las que ya tienen
        // forma de acteco. Las otras 1.993 no se pueden alcanzar por código.
        foreach (DB::connection(self::CONN)->table($this->califica('cwtgiro'))->pluck('GirCod') as $codigo) {
            $codigo = trim((string) $codigo);

            if (strlen($codigo) === 6 && ctype_digit($codigo)) {
                $this->giros[$codigo] = $codigo;
            }
        }

        // Lo nuestro manda: es donde se dice que un acteco apunta a un giro
        // histórico en vez de estrenar fila.
        foreach (DB::connection(self::CONN)->table($this->califica('ventas.giro_sii'))->get() as $fila) {
            $this->giros[$fila->acteco] = trim((string) $fila->gir_cod);
        }

        return $this->giros;
    }

    /**
     * El nombre del objeto, con el prefijo de la base de pruebas si la hay.
     *
     * `ventas.giro_sii` ya trae su esquema, así que se deja tal cual.
     */
    private function califica(string $objeto): string
    {
        $calificado = str_contains($objeto, '.') ? $objeto : "softland.{$objeto}";

        return $this->base ? "{$this->base}.{$calificado}" : $calificado;
    }
}
