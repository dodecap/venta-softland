<?php

namespace App\Support;

/**
 * Texto que llega de fuera y tiene que salir por una respuesta JSON.
 *
 * ## Qué problema resuelve
 *
 * JSON es UTF-8 por definición, y `json_encode` no transige: un solo byte que
 * no sea UTF-8 válido y devuelve `false`. Laravel convierte eso en una
 * `InvalidArgumentException` dentro de `JsonResponse::setData()`, o sea **ya
 * fuera del `try` del controlador**, y el cliente recibe un 500 pelado.
 *
 * Eso es lo que escondió el fallo de la factura 238 el 25-09-2026. El
 * controlador hizo su trabajo: cazó la excepción, la puso en
 * `['error' => $e->getMessage()]` y devolvió 201 con el documento guardado y el
 * envío marcado como fallido, que es exactamente lo que debía pasar. Pero el
 * mensaje venía del controlador ODBC, escrito por Windows **en la página de
 * códigos del sistema** —«No hay ninguna asignación en la página de códigos…»,
 * con esa «ó» en un byte—, así que la respuesta no se pudo serializar y el
 * teléfono vio «error del servidor» sin una palabra de por qué.
 *
 * El error de verdad tardó dos horas en salir, y estaba escrito en la propia
 * respuesta que no se pudo enviar.
 *
 * ## La regla
 *
 * **Un mensaje de excepción no es texto: son los bytes que puso quien la lanzó.**
 * La base los manda en UTF-8 porque el controlador traduce lo que lee, pero sus
 * propios mensajes de error los escribe el sistema operativo, y ahí no traduce
 * nadie. Lo mismo vale para cualquier cosa que venga de `exec()`, de un archivo
 * subido o de una cabecera HTTP.
 *
 * Y lo mismo vale para lo que contesta el SII, que es el otro caller de esta
 * clase: sus `.jws` declaran `ISO-8859-1` y sus glosas llevan acentos.
 *
 * No se convierte a ciegas: se comprueba y sólo se arregla lo roto. Convertir
 * dos veces un texto que ya estaba bien deja «Distribución» en
 * «DistribuciÃ³n», que es peor que el 500 porque no se queja.
 *
 * Y no se arregla desde ISO-8859-1 sino desde **Windows-1252**, que es lo que
 * escribe Windows: las dos coinciden salvo en `0x80`–`0x9F`, donde la primera
 * no tiene nada y la segunda tiene las comillas tipográficas y el guión largo
 * que los mensajes del sistema usan.
 */
final class Texto
{
    /**
     * Lo que haya dentro, en UTF-8 válido, sin tocar lo que ya lo era.
     *
     * Recorre arreglos y objetos porque la respuesta es un árbol y el byte malo
     * puede estar en cualquier hoja —normalmente en un `error` de tercer nivel,
     * que es donde acaban los mensajes de las excepciones cazadas.
     *
     * Se llama **sólo cuando la serialización ya falló**: en el camino bueno no
     * cuesta nada, porque no se llama. Recorrer cada cadena de una página de
     * maestros con miles de filas por si acaso sería pagar el arreglo en todas
     * las peticiones para usarlo en ninguna.
     */
    public static function utf8(mixed $valor): mixed
    {
        if (is_string($valor)) {
            return self::cadena($valor);
        }

        if (is_array($valor)) {
            $limpio = [];

            foreach ($valor as $clave => $uno) {
                $limpio[is_string($clave) ? self::cadena($clave) : $clave] = self::utf8($uno);
            }

            return $limpio;
        }

        // Un objeto se recorre por sus propiedades públicas y se devuelve como
        // arreglo: `json_encode` iba a verlo así de todos modos, y reconstruir
        // la clase original no aporta nada a estas alturas.
        if (is_object($valor) && ! $valor instanceof \JsonSerializable) {
            return self::utf8(get_object_vars($valor));
        }

        return $valor;
    }

    /**
     * Una cadena, arreglada si hace falta.
     */
    public static function cadena(string $texto): string
    {
        if ($texto === '' || mb_check_encoding($texto, 'UTF-8')) {
            return $texto;
        }

        return mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }
}
