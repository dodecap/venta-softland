<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Lo que el servidor necesita tener antes de que la instalación pueda empezar.
 *
 * Esto se mira **antes** de enseñar el formulario de /setup, y no después de
 * pulsar «Instalar». La razón es que cuando algo de esto falta, el error que
 * sale sin comprobarlo no dice lo que pasa: sin `APP_KEY` la conexión no se
 * puede cifrar y revienta con «Unsupported cipher», y sin `pdo_sqlsrv` el
 * mensaje habla de un driver que nadie ha nombrado. Quien instala está en el
 * servidor de un cliente, sin este repositorio delante, y merece que la página
 * le diga qué le falta y cómo se arregla.
 *
 * `bin/instalar.cmd` deja todo esto resuelto. Esta clase es la que comprueba
 * que de verdad quedó resuelto.
 */
class Requisitos
{
    /**
     * Extensiones de PHP sin las cuales la app no arranca, con para qué sirve
     * cada una: un nombre suelto en una lista no le dice nada a nadie.
     */
    private const EXTENSIONES = [
        'pdo_sqlsrv' => 'hablar con SQL Server',
        'sqlsrv' => 'hablar con SQL Server',
        'openssl' => 'cifrar la conexión guardada y firmar el DTE',
        'mbstring' => 'el texto en castellano y el ISO-8859-1 del SII',
        'fileinfo' => 'comprobar los archivos que se suben',
        'gd' => 'el logo de la empresa en los documentos',
        'dom' => 'el XML del documento tributario',
        'curl' => 'las llamadas al SII y al correo',
    ];

    /**
     * Cada comprobación: `ok`, qué es y qué hacer si falla.
     *
     * @return array<int, array{ok: bool, que: string, detalle: string, arreglo: string}>
     */
    public static function todas(): array
    {
        return array_merge(
            [static::php()],
            static::extensiones(),
            [static::clave(), static::escribible('storage/app/private', 'la conexión cifrada y el certificado'),
                static::escribible('bootstrap/cache', 'la configuración compilada')],
        );
    }

    /** ¿Se puede seguir? Si algo imprescindible falta, el formulario no se dibuja. */
    public static function listo(): bool
    {
        foreach (static::todas() as $r) {
            if (! $r['ok']) {
                return false;
            }
        }

        return true;
    }

    private static function php(): array
    {
        $minimo = '8.3.0';

        return [
            'ok' => version_compare(PHP_VERSION, $minimo, '>='),
            'que' => 'PHP '.$minimo.' o superior',
            'detalle' => 'Este servidor corre PHP '.PHP_VERSION.'.',
            'arreglo' => 'Actualiza XAMPP a una versión con PHP '.$minimo.' o superior.',
        ];
    }

    private static function extensiones(): array
    {
        $fila = [];

        foreach (static::EXTENSIONES as $ext => $para) {
            $cargada = extension_loaded($ext);
            $fila[] = [
                'ok' => $cargada,
                'que' => 'Extensión '.$ext,
                'detalle' => $cargada ? 'Cargada. Sirve para '.$para.'.' : 'No está cargada. Sirve para '.$para.'.',
                'arreglo' => in_array($ext, ['pdo_sqlsrv', 'sqlsrv'], true)
                    ? 'Descarga los drivers de Microsoft para tu versión de PHP, copia los .dll en '
                        .'la carpeta ext de PHP y añade extension='.$ext.' a php.ini.'
                    : 'Quita el punto y coma de extension='.$ext.' en php.ini y reinicia Apache.',
            ];
        }

        return $fila;
    }

    /**
     * Sin `APP_KEY` no hay cifrado, y la conexión a Softland se guarda cifrada:
     * es lo primero que se rompe y lo último que se adivina por el mensaje.
     */
    private static function clave(): array
    {
        $hay = (string) config('app.key') !== '';

        return [
            'ok' => $hay,
            'que' => 'Clave de la aplicación',
            'detalle' => $hay
                ? 'Generada. Con ella se cifra la conexión a Softland en disco.'
                : 'No hay APP_KEY en el archivo .env, y sin ella la conexión no se puede guardar cifrada.',
            'arreglo' => 'Ejecuta bin\\instalar.cmd, o a mano: php artisan key:generate',
        ];
    }

    private static function escribible(string $relativa, string $para): array
    {
        $ruta = base_path($relativa);

        // Crear antes de preguntar: en una instalación recién clonada la
        // carpeta puede no existir todavía, y «no existe» no es «no se puede».
        try {
            File::ensureDirectoryExists($ruta);
        } catch (\Throwable) {
            // Da igual: la comprobación de abajo lo dirá igual.
        }

        $ok = is_dir($ruta) && is_writable($ruta);

        return [
            'ok' => $ok,
            'que' => 'Carpeta '.$relativa,
            'detalle' => $ok
                ? 'Se puede escribir. Ahí va '.$para.'.'
                : 'No se puede escribir, y ahí va '.$para.'.',
            'arreglo' => 'Dale permiso de escritura al usuario con el que corre Apache '
                .'sobre '.$relativa.'.',
        ];
    }
}
