<?php
/*
 * Lo que hay que dejar hecho antes de que Laravel pueda arrancar, la primera
 * vez que se abre una instalación recién descomprimida.
 *
 * ## Por qué esto existe y no lo hace la consola
 *
 * Lo hace `bin/instalar.cmd`, y sigue ahí para quien tenga una consola delante.
 * Pero el paquete de instalación se descomprime dentro de XAMPP y se abre en un
 * navegador, y quien lo hace no siempre tiene consola ni por qué pedirla. Así
 * que lo imprescindible se hace en la primera petición.
 *
 * Y es imprescindible de verdad: **sin `.env` Laravel no arranca ni para enseñar
 * `/setup`**. El driver de sesiones por omisión del framework es la base de
 * datos, que es justo lo que todavía no está configurado, y sin sesiones no hay
 * formulario con CSRF. `.env.example` pone `SESSION_DRIVER=file` a propósito; el
 * problema es que nadie lo ha copiado todavía.
 *
 * ## Cuándo corre
 *
 * `public/index.php` lo llama sólo cuando no hay `.env`, que es una llamada a
 * `is_file()` por petición. Después de la primera vez no vuelve a correr.
 *
 * Aquí no hay Laravel: esto pasa antes del autoloader. Sólo PHP a secas, así que
 * nada de `Support\Texto`, `Support\Rutas` ni helpers del framework.
 */

$raiz = dirname(__DIR__);

/**
 * Morir explicándolo.
 *
 * Una pantalla en blanco en este punto no se puede diagnosticar: no hay registro
 * donde escribir —puede que sea justo `storage/logs` lo que falla— ni página de
 * error del framework, porque el framework no ha arrancado. Quien está
 * instalando está en el servidor de un cliente sin este repositorio delante, así
 * que el mensaje tiene que decir qué pasa y qué se hace.
 */
$morir = function (string $titulo, string $cuerpo) use ($raiz): never {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');

    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        .'<meta name="viewport" content="width=device-width, initial-scale=1">'
        .'<title>Venta Softland — no se pudo arrancar</title><style>'
        .'body{margin:0;min-height:100vh;background:#f4f4f4;color:#2b2b2b;padding:32px 16px;'
        .'font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;'
        .'display:flex;align-items:flex-start;justify-content:center}'
        .'.t{width:100%;max-width:560px;background:#fff;border-radius:10px;overflow:hidden;'
        .'box-shadow:0 2px 10px rgba(0,0,0,.12)}'
        .'.c{background:#1d1060;color:#fff;padding:22px 26px;border-bottom:5px solid #26bdef}'
        .'.c h1{margin:0;font-size:19px}.c p{margin:6px 0 0;font-size:13px;opacity:.8}'
        .'.b{padding:26px;font-size:14px;line-height:1.7}'
        .'code{background:#f4f4f4;padding:2px 6px;border-radius:4px;font-size:13px;color:#1d1060}'
        .'pre{background:#f4f4f4;padding:12px;border-radius:6px;font-size:13px;overflow-x:auto}'
        .'</style></head><body><div class="t"><div class="c">'
        .'<h1>Venta Softland</h1><p>La instalación no pudo arrancar</p></div>'
        .'<div class="b"><p><strong>'.htmlspecialchars($titulo, ENT_QUOTES).'</strong></p>'
        .$cuerpo
        .'<p style="color:#919191;font-size:12px;margin-top:22px">Carpeta: <code>'
        .htmlspecialchars($raiz, ENT_QUOTES).'</code></p>'
        .'</div></div></body></html>';

    exit;
};

// ------------------------------------------------------------------ 1. vendor
//
// Se comprueba aquí porque el error de PHP sin comprobarlo es «failed to open
// stream: vendor/autoload.php», que no le dice a nadie que lo que falta son las
// dependencias ni de dónde salen.
if (! is_file($raiz.'/vendor/autoload.php')) {
    $morir('Faltan las dependencias del proyecto.',
        '<p>No existe <code>vendor/autoload.php</code>. El paquete de instalación las trae '
        .'dentro; si esta carpeta salió de <code>git clone</code>, hay que traerlas con '
        .'Composer desde una consola en esta misma carpeta:</p>'
        .'<pre>C:\\xampp\\php\\php.exe composer.phar install --no-dev --optimize-autoloader</pre>');
}

// -------------------------------------------------- 2. carpetas de escritura
//
// Antes del `.env`: si no se puede escribir, mejor decirlo nombrando la carpeta
// que dejar un `.env` a medias.
$carpetas = [
    // La conexión cifrada, el certificado del DTE, la identidad de la empresa,
    // el APK publicado y el estado de la última actualización.
    'storage/app/private',
    'storage/framework/cache/data',
    'storage/framework/sessions',    // sin esto no hay formulario en /setup
    'storage/framework/views',
    'storage/logs',
    'bootstrap/cache',
];

foreach ($carpetas as $c) {
    $ruta = $raiz.'/'.$c;

    if (! is_dir($ruta)) {
        @mkdir($ruta, 0775, true);
    }

    if (! is_dir($ruta) || ! is_writable($ruta)) {
        $morir('No se puede escribir en las carpetas del servidor.',
            '<p>Falta permiso de escritura en <code>'.htmlspecialchars($c, ENT_QUOTES).'</code>.</p>'
            .'<p>Dale permiso de escritura sobre la carpeta del proyecto al usuario con el que '
            .'corre Apache. En un XAMPP recién instalado es el que abrió el panel de control; '
            .'si Apache está como servicio de Windows suele ser <code>SYSTEM</code>.</p>'
            .'<p>Suele pasar por descomprimir el paquete en una carpeta protegida. '
            .'<code>C:\\xampp\\htdocs</code> no lo es.</p>');
    }
}

// ----------------------------------------------------- 3. el `.env` y su clave
//
// Se escriben de una vez y con `rename`, no `copy` y después la clave: así el
// `.env` nunca existe sin `APP_KEY` dentro. Si existiera, la comprobación de
// `public/index.php` —que mira si hay `.env`— no volvería a llamar aquí, y la
// instalación quedaría con una clave vacía. Y la `APP_KEY` de este proyecto no
// cifra sólo la sesión: con ella se guardan la conexión a SQL Server, la clave
// del certificado del DTE y la del SMTP.
if (! is_file($raiz.'/.env')) {
    if (! is_file($raiz.'/.env.example')) {
        $morir('Falta <code>.env.example</code>.',
            '<p>Es la plantilla de la configuración y el paquete la trae. Vuelve a '
            .'descomprimir el ZIP completo sobre esta carpeta.</p>');
    }

    $plantilla = (string) file_get_contents($raiz.'/.env.example');

    // 32 bytes de verdad. Es la clave con la que se cifra lo de arriba, así que
    // no vale un md5 de la hora.
    $clave = 'base64:'.base64_encode(random_bytes(32));
    $env = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$clave, $plantilla, 1);

    if (! str_contains((string) $env, 'APP_KEY='.$clave)) {
        $env = rtrim($plantilla, "\n")."\nAPP_KEY=".$clave."\n";
    }

    $temporal = $raiz.'/.env.'.bin2hex(random_bytes(4)).'.tmp';

    if (@file_put_contents($temporal, $env) === false) {
        $morir('No se pudo escribir la configuración.',
            '<p>No se puede crear el archivo <code>.env</code> en la carpeta del proyecto. '
            .'Es el mismo permiso de escritura de antes, pero sobre la carpeta raíz.</p>');
    }

    // Dos peticiones a la vez la primera vez: la que pierde no puede renombrar
    // encima —en Windows `rename` falla si el destino existe— y no pasa nada,
    // porque la que ganó escribió un `.env` igual de bueno.
    if (! @rename($temporal, $raiz.'/.env')) {
        @unlink($temporal);
    }
}

// ------------------------------------------- 4. la carpeta donde quedó Apache
//
// `RewriteBase` tiene que decir la dirección por la que se pide la aplicación, y
// ésta es la reparación para cuando no coincide.
//
// Sólo hace falta para `public/.htaccess`, que es el que manda cuando la
// aplicación se publica con un `Alias` de Apache —el camino de `srv`, donde la
// línea dice `/venta-softland/`—: ahí la dirección y la carpeta del disco no se
// parecen y Apache no puede deducir una de la otra. El `.htaccess` de la raíz
// **no lleva** la línea a propósito, así que para él esto no encuentra nada que
// arreglar y no hace nada. Ver el comentario de ese archivo.
//
// Se puede llegar aquí con el `RewriteBase` equivocado porque pedir la carpeta a
// secas —`http://servidor/la-carpeta/`— no pasa por ninguna regla de rewrite: lo
// sirve `DirectoryIndex`. Así que ése es el camino que siempre funciona, y es el
// que arregla el resto.
$guion = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$base = rtrim(str_replace('\\', '/', dirname($guion)), '/').'/';

// Cuál de los dos: el puente de la raíz (paquete descomprimido en htdocs) o el
// `public/index.php` de siempre (publicado con el Alias de Apache). Cada uno
// tiene su propio `.htaccess` y sólo manda el del que atendió la petición.
$entrada = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
$archivo = basename(dirname($entrada)) === 'public' ? 'public/.htaccess' : '.htaccess';
$ruta = $raiz.'/'.$archivo;

if ($base !== '' && $base[0] === '/' && is_file($ruta) && is_writable($ruta)) {
    $htaccess = (string) file_get_contents($ruta);

    if (preg_match('/^\s*RewriteBase\s+(\S+)/m', $htaccess, $m) && $m[1] !== $base) {
        file_put_contents($ruta, preg_replace(
            '/^(\s*RewriteBase\s+)\S+/m', '${1}'.$base, $htaccess, 1
        ));
    }
}
