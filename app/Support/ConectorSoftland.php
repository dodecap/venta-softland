<?php

namespace App\Support;

use Illuminate\Database\Connectors\SqlServerConnector;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * El conector de SQL Server, reintentando lo que falla al conectar y se
 * arregla solo.
 *
 * Medido en producción el 24 y el 25 de septiembre de 2026: la conexión a la
 * base —que está en esta misma máquina— falla a rachas con
 * `SQLSTATE[08001] … Encryption not supported on the client`, mezclada con
 * peticiones que van bien en el mismo minuto (55 bien y una mal a las 16:55).
 * No es un trabajador envejecido ni envenenado: el que fallaba tenía 80
 * minutos, reiniciar Apache no lo arreglaba —las rachas volvían 21 minutos
 * después y paraban solas— y 120 conexiones cortas seguidas desde la consola
 * no lo reproducen. Es el arranque de TLS en el cliente, que no siempre sale
 * en una máquina con el 3,6 % de la memoria física libre.
 *
 * Lo primero es no pedir TLS donde no hace falta, y eso lo decide
 * `SoftlandConnection::cifrado()`. Esto es lo segundo: un fallo que dura
 * milisegundos no puede ser un 500 en el teléfono de un vendedor.
 *
 * Se reintenta **sólo al conectar**, que es lo único idempotente por
 * definición —todavía no se ha mandado ninguna instrucción—, y sólo lo que
 * está medido que se arregla solo. Lo demás sube tal cual: una contraseña
 * equivocada reintentada tres veces sigue siendo una contraseña equivocada, y
 * encima tarda el triple en decirlo.
 */
class ConectorSoftland extends SqlServerConnector
{
    /** Intentos en total, contando el primero. */
    public const INTENTOS = 3;

    /** Espera antes del siguiente intento, en microsegundos (se multiplica por el intento). */
    public const ESPERA = 150_000;

    /**
     * Lo que se reintenta, por su texto y no por su SQLSTATE: `08001` es
     * «no pude conectar» y el driver lo usa también para un servidor apagado
     * o un nombre de instancia que no existe, que no se arreglan esperando
     * 150 ms.
     */
    protected const TRANSITORIOS = [
        'Encryption not supported on the client',
        'SSL Provider',
        'Communication link failure',
        'The wait operation timed out',
        'The semaphore timeout period has expired',
        'Unable to complete login process due to delay in login response',
    ];

    /** @param  array<string, mixed>  $config */
    public function createConnection($dsn, array $config, array $options)
    {
        for ($intento = 1; ; $intento++) {
            try {
                return parent::createConnection($dsn, $config, $options);
            } catch (Throwable $e) {
                if ($intento >= static::INTENTOS || ! static::transitorio($e)) {
                    throw $e;
                }

                // Un reintento que no se cuenta es una avería que no existe.
                // Del mensaje sólo el principio, y nunca el DSN.
                //
                // Y anotarlo no puede ser lo que rompa la conexión: esto corre
                // al abrir la base, que es antes de muchas cosas, y si el
                // registro no está en pie la excepción de la fachada taparía
                // justo el fallo que se está intentando resolver.
                try {
                    Log::warning(sprintf('Conexión a SQL Server, intento %d de %d: %s',
                        $intento, static::INTENTOS,
                        substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 200)));
                } catch (Throwable) {
                    // Ni una palabra: lo que importa es el reintento.
                }

                usleep(static::ESPERA * $intento);
            }
        }
    }

    /** ¿Es de los que se arreglan solos? */
    protected static function transitorio(Throwable $e): bool
    {
        foreach (static::TRANSITORIOS as $aguja) {
            if (str_contains($e->getMessage(), $aguja)) {
                return true;
            }
        }

        return false;
    }
}
