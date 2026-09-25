<?php

namespace App\Http;

use App\Support\Texto;
use Illuminate\Routing\ResponseFactory;
use InvalidArgumentException;

/**
 * La fábrica de respuestas de Laravel, con una red debajo.
 *
 * ## Por qué esto y no arreglar cada sitio
 *
 * Hay 153 `response()->json(...)` en la aplicación y 63 sitios que meten un
 * `$e->getMessage()` dentro de uno. Un byte que no sea UTF-8 en cualquiera de
 * ellos tira la serialización **después** de que el controlador haya acabado, y
 * lo que llega al teléfono es un 500 sin explicación en lugar del error que el
 * controlador había preparado con cuidado. Le pasó a la factura 238.
 *
 * Arreglar los 63 sitios es escribir la misma regla 63 veces y olvidarla en el
 * 64. Así que se escribe una vez, aquí: **ninguna respuesta de esta API se
 * convierte en un 500 por su codificación.**
 *
 * ## Por qué se intenta primero y se arregla después
 *
 * Lo evidente sería limpiar el árbol antes de serializarlo. Sale caro y para
 * nada: una página de maestros son miles de filas y decenas de miles de
 * cadenas, todas buenas, que vienen del controlador ODBC ya traducidas a UTF-8.
 * Recorrerlas por si acaso es pagar en todas las peticiones un arreglo que se
 * usa en una entre diez mil.
 *
 * `json_encode` ya hace ese recorrido y ya sabe decir si algo va mal. Así que
 * se le deja intentarlo: si sale, no se ha gastado nada; si no sale, **ahí** se
 * limpia y se vuelve a intentar. El camino bueno no cambia en absoluto.
 *
 * No enmascara nada que antes se viera: hoy ese caso es un 500 sin cuerpo. Con
 * esto, el cliente recibe el mensaje que el controlador quiso mandarle, con sus
 * acentos puestos.
 */
class Respuestas extends ResponseFactory
{
    /**
     * @param  mixed  $data
     * @param  int  $status
     * @param  int  $options
     */
    public function json($data = [], $status = 200, array $headers = [], $options = 0)
    {
        try {
            return parent::json($data, $status, $headers, $options);
        } catch (InvalidArgumentException) {
            // `JsonResponse::setData()` sólo lanza esto cuando `json_encode`
            // falla, y la única causa posible aquí es la codificación: la
            // recursión y los tipos no serializables no llegan a esta API.
            return parent::json(Texto::utf8($data), $status, $headers, $options);
        }
    }
}
