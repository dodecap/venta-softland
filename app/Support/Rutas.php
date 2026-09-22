<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Direcciones que el servidor puede escribir sin equivocarse.
 *
 * Ninguna dirección absoluta generada aquí sirve detrás de un proxy inverso.
 * Medido: `https://venta.netdomain.cl/setup` redirigía a
 * `https://venta.netdomain.cl/venta-softland`, que **da 404** — el proxy pone
 * la carpeta al reenviar, así que la carpeta que Laravel ve por dentro no
 * existe por fuera. Y esto no es cosa de esta instalación: cualquier cliente
 * que publique el servidor detrás de IIS, nginx o Cloudflare choca igual, en
 * la primera página que abre.
 *
 * La regla del proyecto es la misma de `/app`: **quien sabe la dirección buena
 * es el navegador**. Así que las redirecciones van en `Location` relativo —
 * legal desde el RFC 7231 y resuelto por todos los navegadores— y el navegador
 * la resuelve contra lo que él tiene en la barra, que es lo correcto por
 * definición.
 */
class Rutas
{
    /**
     * La ruta relativa desde la petición actual hasta `$destino`, que se cuenta
     * desde la raíz de la aplicación.
     *
     * Un `Location` relativo se resuelve contra el **directorio** de la
     * dirección actual, así que hay que subir un nivel por cada segmento de
     * más: desde `/api/ping` la raíz está en `../`, desde `/setup` está en el
     * mismo sitio y desde `/` también.
     */
    public static function relativa(Request $request, string $destino = ''): string
    {
        $segmentos = array_values(array_filter(explode('/', $request->getPathInfo()), 'strlen'));
        $subir = max(0, count($segmentos) - 1);

        return str_repeat('../', $subir).ltrim($destino, '/');
    }

    /**
     * Una redirección que sobrevive a un proxy inverso.
     *
     * Se construye la respuesta a mano en vez de con `response()`: así esto se
     * puede probar sin levantar la aplicación entera, que es justo lo que hay
     * que poder hacer con la regla de la que dependen las tres páginas.
     */
    public static function irA(Request $request, string $destino = ''): Response
    {
        $ruta = static::relativa($request, $destino);

        // Un `Location` vacío no es válido: quedarse en el mismo sitio se dice
        // con `./`, no con nada.
        return new Response('', 302, ['Location' => $ruta === '' ? './' : $ruta]);
    }
}
