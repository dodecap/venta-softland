<?php

namespace App\Services\Documentos;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * La empresa que emite el documento: quién es, cómo se ve y qué promete.
 *
 * ## Softland propone, la configuración dispone
 *
 * Casi toda la ficha ya está en `softland.soempre` — RUT, razón social, giro,
 * dirección, comuna, fono, sitio web — y pedirla otra vez a mano es la forma
 * más segura de que los dos datos queden distintos. Por eso cada campo tiene un
 * valor heredado del ERP y un override opcional guardado en `ventas.config`.
 *
 * Que el override exista no es capricho: el PDF que los vendedores usaban lleva
 * en el pie «Avda. Los Carreras 1865, Concepción» y `soempre.Dire` dice
 * «Ensenada 2332, Los Ángeles». Las dos son ciertas — una es el domicilio
 * tributario y la otra la oficina comercial. Softland guarda la legal; el papel
 * que ve el cliente muestra la que corresponda.
 *
 * La consecuencia buena es que una instalación nueva **funciona sin configurar
 * nada**: el día uno el documento ya sale con el RUT y la razón social bien,
 * sin logo. Eso es lo que hace replicable esto a otra empresa Softland.
 */
class Identidad
{
    public const CLAVE = 'identidad';

    /** Ancho máximo del logo ya guardado. En A4 ocupa unos 45 mm: más resolución sólo engorda el correo. */
    public const ANCHO_LOGO = 600;

    /** Lo que el administrador puede escribir. Nada fuera de esta lista entra. */
    public const CAMPOS = [
        'razon_social', 'nombre_comercial', 'rut', 'giro',
        'direccion', 'comuna', 'ciudad', 'fono', 'email', 'web',
        'color', 'condiciones_comerciales', 'datos_bancarios',
        'pie_documento', 'vigencia_cotizacion_dias', 'modelo_negocio',
        // Lo que sale en el papel legal. Softland ya lo tiene todo —la oficina
        // del SII es la ciudad del contribuyente, y la resolución está en
        // `soempre`—, así que normalmente no hay nada que escribir aquí.
        'sii_oficina', 'sii_resolucion', 'sii_resolucion_anio',
        // La voz de la empresa en la cotización: cómo abre, qué dice del pago y
        // cómo se despide. Son textos, no lógica, y por eso se editan.
        'presentacion', 'nota_pago', 'despedida',
        // La oficina comercial, que puede no ser el domicilio tributario. Ver
        // el comentario de `heredado()`.
        'direccion_comercial', 'comuna_comercial',
    ];

    /** Índigo corporativo de Softland, el mismo de la app. */
    public const COLOR_POR_DEFECTO = '#1d1060';

    private static ?array $cache = null;

    // ------------------------------------------------------------- lectura

    /**
     * La ficha completa, ya resuelta: override si lo hay, Softland si no.
     *
     * Se memoriza por petición porque el PDF la pide varias veces — una por
     * bloque — y no tiene sentido volver a leer `soempre` cada vez.
     */
    public function actual(): array
    {
        if (static::$cache !== null) {
            return static::$cache;
        }

        $heredado = $this->heredado();
        $propio = $this->overrides();

        $ficha = [];
        foreach (static::CAMPOS as $campo) {
            $valor = trim((string) ($propio[$campo] ?? ''));
            $ficha[$campo] = $valor !== '' ? $valor : ($heredado[$campo] ?? '');
        }

        $ficha['color'] = $this->colorValido($ficha['color']);
        $ficha['vigencia_cotizacion_dias'] = (int) ($ficha['vigencia_cotizacion_dias'] ?: 30);
        $ficha['modelo_negocio'] = in_array($ficha['modelo_negocio'], ['PRODUCTOS', 'SERVICIOS', 'MIXTO'], true)
            ? $ficha['modelo_negocio']
            : 'MIXTO';
        $ficha['tiene_logo'] = File::exists($this->rutaLogo());
        $ficha['tiene_logo_secundario'] = File::exists($this->rutaLogo('secundario'));

        return static::$cache = $ficha;
    }

    /**
     * Lo que la app le manda a la pantalla de administración: el valor propio y
     * el heredado por separado, para que el administrador vea qué está
     * cambiando y contra qué. Mezclarlos aquí le quitaría esa información.
     */
    public function paraEditar(): array
    {
        return [
            'propio' => $this->overrides(),
            'heredado' => $this->heredado(),
            'efectivo' => $this->actual(),
        ];
    }

    /** Los valores que vienen del ERP y no se escriben desde la app. */
    public function heredado(): array
    {
        $e = DB::connection('softland')->table('softland.soempre')->first();

        $t = fn ($v) => trim((string) ($v ?? ''));

        return [
            'razon_social' => $t($e->NomB ?? ''),
            'nombre_comercial' => $t($e->NomB ?? ''),
            'rut' => $t($e->RutE ?? ''),
            'giro' => $t($e->Giro ?? ''),
            'direccion' => $t($e->Dire ?? ''),
            'comuna' => $t($e->Comu ?? ''),
            'ciudad' => $t($e->Ciud ?? ''),
            'fono' => $t($e->Fono ?? ''),
            'email' => $t($e->EMailDTE ?? ''),
            'web' => $t($e->SitioWEB ?? ''),
            'color' => static::COLOR_POR_DEFECTO,
            'condiciones_comerciales' => '',
            'datos_bancarios' => '',
            'pie_documento' => '',
            'vigencia_cotizacion_dias' => '30',
            'modelo_negocio' => 'MIXTO',
            // «S.I.I. - LOS ANGELES»: la oficina que fiscaliza, que es la
            // ciudad del domicilio tributario.
            'sii_oficina' => $t($e->Ciud ?? ''),
            // «Res.Nº 80 de 2014»: la resolución que autorizó a la empresa a
            // emitir documentos electrónicos.
            'sii_resolucion' => $t($e->DTENumeroResol ?? ''),
            'sii_resolucion_anio' => substr($t($e->DTEFechaResol ?? ''), 0, 4),
            // Los textos con los que sale la cotización el primer día. No son
            // de ninguna empresa en particular —son la fórmula de cualquier
            // cotización comercial— y se cambian desde Identidad.
            'presentacion' => 'Atendiendo a su amable solicitud estamos enviando cotización de los '
                .'productos requeridos, para nosotros es un placer poner nuestra empresa a su servicio.',
            'nota_pago' => 'En caso de pago con cheques, el primero debe ser al día y su monto será '
                .'de acuerdo con la distribución del número de cheques que se acuerde, debiendo '
                .'siempre cubrir el IVA en el primer cheque.',
            'despedida' => 'Agradeciendo su atención y confianza hacia nuestra empresa le hago llegar '
                .'mis saludos quedando a su disposición para atender cualquier consulta respecto de '
                .'la presente cotización.',
            /*
             * La oficina comercial, que **no** es la dirección tributaria.
             *
             * Las dos son ciertas y las dos salen impresas, en papeles
             * distintos: el domicilio tributario va en la factura —tiene que
             * decir lo mismo que el XML que recibió el SII— y la oficina
             * comercial va en el pie de la cotización, que es a dónde va el
             * cliente. INNOVAGES tributa en Ensenada 2332, Los Ángeles, y
             * atiende en Los Carreras 1865, Concepción.
             *
             * Por eso son campos aparte y no un override de `direccion`:
             * pisarla cambiaría también la cabecera del documento legal, y ahí
             * sería un error.
             */
            'direccion_comercial' => $t($e->Dire ?? ''),
            'comuna_comercial' => $t($e->Comu ?? ''),
        ];
    }

    /** Sólo lo que escribió el administrador. */
    public function overrides(): array
    {
        $json = DB::connection('softland')->table('ventas.config')
            ->where('clave', static::CLAVE)->value('valor');

        $datos = $json ? json_decode($json, true) : [];

        return is_array($datos) ? $datos : [];
    }

    // ------------------------------------------------------------ escritura

    /** Guarda los overrides. Un campo en blanco **borra** el override y devuelve el mando a Softland. */
    public function guardar(array $datos): void
    {
        $limpio = [];
        foreach (static::CAMPOS as $campo) {
            if (! array_key_exists($campo, $datos)) {
                continue;
            }
            $valor = trim((string) $datos[$campo]);
            if ($valor !== '') {
                $limpio[$campo] = $valor;
            }
        }

        DB::connection('softland')->table('ventas.config')->updateOrInsert(
            ['clave' => static::CLAVE],
            ['valor' => json_encode($limpio, JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
        );

        static::$cache = null;
    }

    // ---------------------------------------------------------------- logo

    /**
     * Fuera de `public/`: un archivo que sube un usuario y que además queda en
     * una ruta que Apache interpreta es la receta clásica de la ejecución
     * remota. Se sirve por la API, con token, y nunca por el servidor web.
     */
    public function rutaLogo(string $cual = 'logo'): string
    {
        return storage_path('app/private/identidad/'.($cual === 'secundario' ? 'logo-2' : 'logo').'.png');
    }

    /**
     * Hay dos logos, y el segundo no es un adorno.
     *
     * INNOVAGES es representante regional de Softland, y su papel lleva los dos
     * escudos: el suyo y el de la marca que representa. En la cotización el de
     * Softland va a la izquierda y el propio a la derecha; en la nota de venta,
     * al revés. Es la identidad que el cliente lleva años recibiendo.
     *
     * Se guarda igual que el primero —decodificado y vuelto a codificar— y vive
     * en el mismo sitio. Una empresa que no represente a nadie no lo sube, y
     * las plantillas dibujan sólo el que haya.
     */
    public function logoSecundarioDataUri(): ?string
    {
        return $this->dataUriDe($this->rutaLogo('secundario'));
    }

    /**
     * Recibe el archivo, lo comprueba y guarda **otro**.
     *
     * El paso que de verdad cierra el caso es el tercero: lo que se guarda no es
     * el archivo que subieron, es un PNG nuevo dibujado a partir de sus píxeles.
     * Lo que venga escondido en los metadatos, en un comentario del PNG o pegado
     * después del fin de la imagen no sobrevive a decodificar y volver a
     * codificar. Comprobar la extensión, en cambio, no protege de nada.
     *
     * @throws \RuntimeException con un motivo que se le puede mostrar al administrador
     */
    public function guardarLogo(UploadedFile $archivo, string $cual = 'logo'): array
    {
        $mime = $archivo->getMimeType();   // por contenido (finfo), no por extensión
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw new \RuntimeException('El logo tiene que ser PNG o JPG.');
        }

        $medidas = @getimagesize($archivo->getPathname());
        if (! $medidas) {
            throw new \RuntimeException('Ese archivo no es una imagen.');
        }

        [$ancho, $alto] = $medidas;
        if ($ancho < 40 || $alto < 40) {
            throw new \RuntimeException('El logo es demasiado pequeño: mínimo 40 × 40 px.');
        }
        if ($ancho > 4000 || $alto > 4000) {
            throw new \RuntimeException('El logo es demasiado grande: máximo 4.000 px por lado.');
        }

        $origen = $mime === 'image/png'
            ? @imagecreatefrompng($archivo->getPathname())
            : @imagecreatefromjpeg($archivo->getPathname());

        if (! $origen) {
            throw new \RuntimeException('No se pudo leer la imagen.');
        }

        // Se reescala sólo hacia abajo: agrandar un logo chico lo deja borroso
        // y no mejora nada en el papel.
        $escala = min(1, static::ANCHO_LOGO / $ancho);
        $nuevoAncho = max(1, (int) round($ancho * $escala));
        $nuevoAlto = max(1, (int) round($alto * $escala));

        $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);

        if ($mime === 'image/png') {
            // Un logo sobre fondo blanco recortado se ve mal en cualquier papel
            // que no sea blanco puro: la transparencia se conserva.
            imagealphablending($destino, false);
            imagesavealpha($destino, true);
            imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
        } else {
            imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
        }

        imagecopyresampled($destino, $origen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
        imagedestroy($origen);

        File::ensureDirectoryExists(dirname($this->rutaLogo($cual)));

        ob_start();
        imagepng($destino, null, 6);
        $png = ob_get_clean();
        imagedestroy($destino);

        File::put($this->rutaLogo($cual), $png);
        static::$cache = null;

        return ['ancho' => $nuevoAncho, 'alto' => $nuevoAlto, 'bytes' => strlen($png)];
    }

    public function borrarLogo(string $cual = 'logo'): void
    {
        File::delete($this->rutaLogo($cual));
        static::$cache = null;
    }

    /**
     * El logo listo para meter en el HTML del PDF.
     *
     * Va incrustado en base64 y no enlazado porque dompdf corre con
     * `isRemoteEnabled = false` a propósito: un `<img src="http://…">` que no
     * responde bloquea el envío del correo. Cuesta un tercio más de bytes y
     * elimina la clase entera de fallos «el PDF salió sin logo».
     */
    public function logoDataUri(): ?string
    {
        return $this->dataUriDe($this->rutaLogo());
    }

    private function dataUriDe(string $ruta): ?string
    {
        return File::exists($ruta)
            ? 'data:image/png;base64,'.base64_encode(File::get($ruta))
            : null;
    }

    // ---------------------------------------------------------------- varios

    /** Un color que no sea `#rrggbb` llegaría al CSS del PDF tal cual. No pasa. */
    private function colorValido(string $color): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : static::COLOR_POR_DEFECTO;
    }

    /** Entre pruebas y entre peticiones de cola: la memoria no puede sobrevivir a un cambio. */
    public static function olvidar(): void
    {
        static::$cache = null;
    }
}
