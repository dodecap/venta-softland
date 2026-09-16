<?php

namespace App\Services\Documentos;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/**
 * El motor: recibe un tipo documental y sus datos, y devuelve un PDF.
 *
 * No sabe de cotizaciones ni de notas de venta. Sabe de bloques, y el tipo le
 * dice cuáles. Todo lo que aquí parezca anticuado — tablas en vez de rejillas,
 * estilos escritos en la etiqueta — está así porque dompdf es un motor de CSS
 * 2.1 y es lo único que dibuja.
 *
 * ## Por qué en el servidor y no en el teléfono
 *
 * Porque el número del documento lo asigna el servidor. Una cotización creada
 * sin señal todavía no tiene `CotNum`, y un PDF que dice «Cotización N° —» no
 * es un documento comercial, es un borrador. Generarlo en el teléfono, además,
 * obligaría a mantener una segunda plantilla en JavaScript: ya sabemos cómo
 * termina eso, es el riesgo que `Totales.php` y su copia en `documentos.js`
 * obligan a vigilar a mano. Allá la duplicación era inevitable — el vendedor
 * tiene que ver el total antes de grabar. Aquí no lo es.
 *
 * El teléfono guarda los bytes que le llegaron y los vuelve a abrir sin señal.
 * Nunca dibuja un PDF: lo archiva.
 */
class Motor
{
    /** Los mismos milímetros que declara `@page` en la plantilla, en puntos. */
    private const MARGEN = 42.5;          // 15 mm

    private const PIE_DESDE_ABAJO = 28.0; // el renglón del paginado

    private const CUERPO_PIE = 7.0;

    public function __construct(private Identidad $identidad) {}

    /** El PDF en binario, listo para adjuntar, guardar o mandar al teléfono. */
    public function pdf(TipoDocumento $tipo, array $datos): string
    {
        return $this->renderizar($this->contexto($tipo, $datos));
    }

    /**
     * El paginado del DTE no lo pone el motor.
     *
     * En la hoja legal el pie está ocupado por el acuse de recibo, que es texto
     * de ley y no se mueve. El «Página 1 de 3» va dentro del recuadro del
     * folio, escrito por la propia plantilla.
     */
    private function llevaPaginado(string $papel): bool
    {
        return $papel !== 'letter';
    }

    /**
     * Dibuja a partir de un contexto ya armado.
     *
     * Separado de `pdf()` porque quien guarda la emisión necesita **el mismo**
     * contexto que se dibujó, no uno equivalente armado una segunda vez: entre
     * las dos llamadas puede cambiar la UF, el logo o la hora.
     */
    public function renderizar(array $contexto): string
    {
        return $this->dibujar($this->htmlDe($contexto), $contexto['tipo']->papel());
    }

    /** El HTML de un contexto ya armado. Es lo que define el documento. */
    public function htmlDe(array $contexto): string
    {
        return View::make('documentos.'.$contexto['tipo']->plantilla(), $contexto)->render();
    }

    /**
     * De HTML a PDF.
     *
     * Público porque quien guarda la emisión necesita dibujar **exactamente el
     * HTML al que le tomó la huella**, no uno equivalente generado otra vez.
     */
    public function dibujar(string $html, string $papel = 'a4'): string
    {
        $opciones = new Options;
        // Sin acceso a la red: el PDF se arma con lo que hay en el HTML y nada
        // más. El logo va incrustado en base64, por eso no hace falta.
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('defaultFont', 'DejaVu Sans');   // la que trae acentos y «ñ»

        $dompdf = new Dompdf($opciones);
        $dompdf->setPaper($papel);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        if ($this->llevaPaginado($papel)) {
            $this->paginar($dompdf);
        }

        return $dompdf->output();
    }

    /**
     * El «Página 1 de 3» del pie.
     *
     * Va **después** de `render()` y no en la plantilla: dentro del HTML sólo se
     * puede escribir con un bloque `<script type="text/php">`, que se ejecuta
     * mientras se maqueta — cuando dompdf todavía no sabe cuántas páginas van a
     * salir. El resultado era un «Página 1 de 1» en un documento de dos, y sólo
     * en la primera. Aquí la maquetación ya terminó y el número es el de verdad.
     *
     * De paso, así no hace falta `isPhpEnabled`: ejecutar PHP desde dentro de
     * una plantilla es una puerta que conviene no dejar abierta aunque la
     * plantilla sea nuestra.
     */
    private function paginar(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();

        // En un documento de una hoja el «Página 1 de 1» no informa de nada y
        // se come el sitio del pie. El paginado existe para el de tres.
        if ($canvas->get_page_count() < 2) {
            return;
        }
        $metrics = $dompdf->getFontMetrics();
        $fuente = $metrics->getFont('DejaVu Sans');

        $texto = 'Página {PAGE_NUM} de {PAGE_COUNT}';
        $ancho = $metrics->getTextWidth(
            str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], [$canvas->get_page_count(), $canvas->get_page_count()], $texto),
            $fuente,
            self::CUERPO_PIE,
        );

        $canvas->page_text(
            $canvas->get_width() - self::MARGEN - $ancho,
            $canvas->get_height() - self::PIE_DESDE_ABAJO,
            $texto,
            $fuente,
            self::CUERPO_PIE,
            [0.43, 0.43, 0.49],
        );
    }

    /** El HTML del documento. Para mirarlo en el navegador al depurar el formato. */
    public function html(TipoDocumento $tipo, array $datos): string
    {
        return $this->htmlDe($this->contexto($tipo, $datos));
    }

    /**
     * Todo lo que los bloques necesitan, resuelto de una vez.
     *
     * En las plantillas no se consulta nada: una vista que hace consultas se cae
     * distinto en cada correo. Lo que aquí no esté, allá no existe.
     */
    public function contexto(TipoDocumento $tipo, array $datos): array
    {
        $doc = $datos['documento'] ?? [];
        $lineas = $datos['lineas'] ?? [];
        $identidad = $this->identidad->actual();

        return $datos + [
            'tipo' => $tipo,
            'bloques' => $tipo->bloques(),
            'identidad' => $identidad,
            'logo' => $this->identidad->logoDataUri(),
            // El segundo escudo: la marca que la empresa representa. Una que no
            // represente a nadie no lo sube y las plantillas dibujan sólo el
            // que haya.
            'logo_secundario' => $this->identidad->logoSecundarioDataUri(),
            // La voz de la empresa, que es texto y no lógica.
            'presentacion' => $identidad['presentacion'] ?? '',
            'nota_pago' => $identidad['nota_pago'] ?? '',
            'despedida' => $identidad['despedida'] ?? '',
            // Las condiciones **sin** la forma de pago ni la UF: en el papel
            // comercial la primera va en la caja del cliente y la segunda cierra
            // la lista, en negrita.
            'condiciones_empresa' => array_merge(
                $this->lineasDe($identidad['condiciones_comerciales'] ?? ''),
                $this->lineasDe($identidad['datos_bancarios'] ?? ''),
            ),
            // La UF con la que se calculó el documento. Sin ella, los números en
            // UF de la rejilla no se pueden comprobar.
            'uf_documento' => $this->ufDelDocumento($datos, $lineas),
            'columnas' => $this->columnas($identidad['modelo_negocio'], $lineas),
            'impuestos' => ! empty($datos['impuestos']) ? $datos['impuestos'] : $this->impuestos($doc),
            'vigencia' => $this->vigencia($tipo, $doc, $identidad),
            'condiciones' => $this->condiciones($datos, $identidad),
            'moneda_simbolo' => $datos['moneda_simbolo'] ?? '$',
            'moneda_nombre' => $datos['moneda_nombre'] ?? 'pesos chilenos',
        ];
    }

    // ------------------------------------------------------------- columnas

    /**
     * Qué columnas lleva el detalle.
     *
     * La de conversión aparece **sola cuando hace falta**: si todas las líneas
     * tienen `equiv = 1` no hay nada que convertir y dibujarla sería una columna
     * de unos. Un tercio de las líneas de INNOVAGES la necesita porque el
     * producto está tarifado en UF y el documento va en pesos; una empresa que
     * no usa UF nunca la ve.
     *
     * El resto sale del modelo de negocio. Un servicio se cotiza por lo que
     * vale, no por lo que cuesta la unidad: ahí la descripción se lleva el ancho
     * y el código se encoge.
     */
    private function columnas(string $modelo, array $lineas): array
    {
        $conversion = false;
        foreach ($lineas as $l) {
            if (abs(((float) ($l['equiv'] ?? 1)) - 1) > 0.000001) {
                $conversion = true;
                break;
            }
        }

        $descuento = false;
        foreach ($lineas as $l) {
            if ((float) ($l['descuento'] ?? 0) > 0) {
                $descuento = true;
                break;
            }
        }

        return [
            'codigo' => $modelo !== 'SERVICIOS',
            'unidad' => $modelo !== 'SERVICIOS',
            'conversion' => $conversion,
            'descuento' => $descuento,
            // Lo que sobra se lo lleva la descripción. Es el requisito duro de
            // una empresa de servicios: «Servicio de implementación,
            // configuración, parametrización y puesta en marcha…» tiene que
            // envolver y hacer crecer la fila, no cortarse.
            'ancho_descripcion' => match (true) {
                $modelo === 'SERVICIOS' => '52%',
                $conversion => '34%',
                default => '44%',
            },
        ];
    }

    // ------------------------------------------------------------ impuestos

    /**
     * Los impuestos del documento, en una lista.
     *
     * El motor dibuja los que le pasen: si algún día se calcula el ILA, entra
     * como una fila más sin tocar la plantilla. Cuando no vienen — que es el
     * caso de un documento leído del maestro, que sólo trae los totales — se
     * deduce el IVA de la resta, que es exactamente como cuadra Softland:
     * `CtMonto = CtSubTotal − CtTotalDesc + Σ Impto`.
     */
    private function impuestos(array $doc): array
    {
        if (isset($doc['impuestos']) && is_array($doc['impuestos'])) {
            return $doc['impuestos'];
        }

        $iva = ((float) ($doc['total'] ?? 0)) - ((float) ($doc['neto'] ?? 0)) - ((float) ($doc['exento'] ?? 0));

        if (abs($iva) < 0.5) {
            return [];
        }

        $pct = (float) ($doc['iva_pct'] ?? 0);

        return [[
            'nombre' => $pct > 0 ? 'IVA '.rtrim(rtrim(number_format($pct, 1, ',', '.'), '0'), ',').' %' : 'IVA',
            'monto' => $iva,
        ]];
    }

    // ------------------------------------------------------------- vigencia

    /** «Válida hasta». Sólo la cotización: una nota de venta no caduca. */
    private function vigencia(TipoDocumento $tipo, array $doc, array $identidad): ?string
    {
        if ($tipo !== TipoDocumento::COTIZACION || empty($doc['fecha'])) {
            return null;
        }

        $dias = (int) $identidad['vigencia_cotizacion_dias'];
        if ($dias <= 0) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($doc['fecha'])->addDays($dias)->format('d-m-Y');
        } catch (\Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------- condiciones

    /**
     * El bloque de condiciones comerciales, armado de tres fuentes.
     *
     * 1. La condición de venta que trae el documento (`cwtconv.CveDes`).
     * 2. El valor de la UF con que se cotizó, si el documento la usa. Va aquí y
     *    no en una nota al pie porque es la que discute el cliente.
     * 3. Lo que escribió el administrador: formas de pago, cuentas, plazos.
     *
     * No se copian las condiciones del PDF de referencia: son de Softland
     * Ingeniería y sólo sirvieron de ejemplo de estructura.
     */
    /** Un texto de varias líneas, como lista. Las vacías se van. */
    private function lineasDe(string $texto): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $texto) ?: [])));
    }

    /**
     * El valor de la UF que usó el documento.
     *
     * Sale de la equivalencia de sus propias líneas y no de la UF de hoy: un
     * documento de hace tres meses se reimprime con la UF de entonces, que es
     * con la que se calcularon sus totales. Sólo aparece si algo va en UF.
     */
    private function ufDelDocumento(array $datos, array $lineas): ?float
    {
        // La equivalencia distinta de 1 es lo que dice que la línea se tarifó
        // en otra moneda: el precio va en la del producto y el total en la del
        // documento. No hace falta mirar qué moneda es.
        foreach ($lineas as $l) {
            if ((float) ($l['equiv'] ?? 1) > 1) {
                return (float) $l['equiv'];
            }
        }

        return null;
    }

    private function condiciones(array $datos, array $identidad): array
    {
        $lineas = [];

        if (! empty($datos['condicion'])) {
            $lineas[] = 'Forma de pago: '.$datos['condicion'];
        }

        if (! empty($datos['uf'])) {
            $lineas[] = 'Valor UF utilizado: $ '.number_format((float) $datos['uf'], 2, ',', '.');
        }

        foreach ([$identidad['condiciones_comerciales'], $identidad['datos_bancarios']] as $texto) {
            foreach (preg_split('/\r\n|\r|\n/', (string) $texto) as $linea) {
                $linea = trim($linea);
                if ($linea !== '') {
                    $lineas[] = $linea;
                }
            }
        }

        return $lineas;
    }
}
