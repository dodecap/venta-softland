<?php

namespace App\Services\Dte;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Dónde vive el certificado digital de la empresa, y quién lo pone ahí.
 *
 * Hasta la 0.46.0 el certificado se copiaba al servidor a mano y su clave se
 * escribía en el `.env`. Funciona, y es lo único de toda la instalación que
 * obligaba a abrir una sesión en el servidor y editar un archivo: en una
 * empresa nueva, y otra vez cada vez que el certificado se renueva, que es una
 * vez al año. Ahora se sube desde la app, con rol admin, y queda aquí.
 *
 * ## Dónde y cómo
 *
 * El `.pfx` tal cual en `certificado-app.pfx`, fuera de git y fuera de
 * `public/`. **La clave, cifrada con `APP_KEY`**, en `certificado-app.json`, al
 * lado. Es exactamente lo que ya se hace con la conexión a SQL Server en
 * `softland.json`, y por la misma razón: un secreto en claro en disco es un
 * secreto que se lleva cualquiera que liste la carpeta.
 *
 * El nombre lleva `-app` y no es capricho: el del `.env` suele llamarse
 * `certificado.pfx` y estar en esta misma carpeta. Si compartieran nombre,
 * «quitar el subido» borraría el archivo al que apunta `DTE_CERT_RUTA` y el
 * respaldo dejaría de existir justo cuando hace falta.
 *
 * ## Tres reglas que no son negociables
 *
 * **Nada vuelve a bajar.** No hay ruta que devuelva el archivo ni la clave. Lo
 * que la app enseña es la ficha —quién firma, su RUT, cuándo vence—, y esa
 * ficha **se deduce del archivo**, no se teclea: un dato escrito a mano puede
 * discrepar del certificado, y el día que discrepa nadie se entera.
 *
 * **Se comprueba abriéndolo.** Mirar la extensión no protege de nada; lo que
 * dice que esto sirve es que OpenSSL abra el PKCS#12 con esa clave y encuentre
 * dentro una llave privada y un certificado. Es la misma regla del logo.
 *
 * **Lo que ya funciona no se toca hasta que lo nuevo valga.** Se valida en
 * memoria y sólo entonces se escribe. Subir un archivo equivocado no puede
 * dejar a la empresa sin poder emitir.
 *
 * El `.env` sigue valiendo como respaldo: una instalación que ya tenía su
 * `DTE_CERT_RUTA` puesto sigue emitiendo sin que nadie haga nada. Lo guardado
 * manda sobre lo del `.env`, porque es lo que alguien decidió después.
 */
class AlmacenCertificado
{
    public static function rutaArchivo(): string
    {
        return self::carpeta().DIRECTORY_SEPARATOR.'certificado-app.pfx';
    }

    private static function rutaFicha(): string
    {
        return self::carpeta().DIRECTORY_SEPARATOR.'certificado-app.json';
    }

    /** Configurable para poder probarlo sin escribir donde está el de verdad. */
    private static function carpeta(): string
    {
        return (string) config('dte.certificado.almacen', storage_path('app/private'));
    }

    /** ¿Hay uno subido desde la app? */
    public static function hay(): bool
    {
        return File::exists(self::rutaArchivo()) && File::exists(self::rutaFicha());
    }

    /**
     * La clave del PKCS#12 guardado, o null si no hay ninguno subido.
     *
     * Lo devuelve para usarlo y descartarlo, como el `.env` de antes: aquí no
     * hay dónde guardarlo más seguro que donde ya está.
     */
    public static function clave(): ?string
    {
        return self::ficha()['clave'] ?? null;
    }

    /**
     * Lo que la app enseña. **Sin la clave**: este arreglo va a una respuesta
     * JSON.
     *
     * @return array<string, mixed>|null
     */
    public static function resumen(): ?array
    {
        $f = self::ficha();

        if ($f === null) {
            return null;
        }

        unset($f['clave']);

        return $f;
    }

    /**
     * Guarda un certificado nuevo, después de comprobar que sirve.
     *
     * @param  string  $quien  el usuario de la app que lo subió, para la ficha
     *
     * @throws RuntimeException si el archivo no es un PKCS#12 o la clave no es ésa
     */
    public static function guardar(UploadedFile $archivo, string $clave, string $quien): array
    {
        $bytes = (string) File::get($archivo->getRealPath());

        if ($bytes === '') {
            throw new RuntimeException('El archivo llegó vacío.');
        }

        // Comprobarlo antes de escribir nada: `desdeContenido` revienta con un
        // mensaje entendible si la clave no es ésa, y el certificado que está
        // funcionando se queda donde está.
        $cert = Certificado::desdeContenido($bytes, $clave);

        $ficha = [
            'sujeto' => $cert->sujeto,
            'rut' => $cert->rut,
            'vence' => $cert->vence,
            'subido_en' => now()->toDateTimeString(),
            'subido_por' => $quien,
            'bytes' => strlen($bytes),
            'clave' => $clave,
        ];

        File::ensureDirectoryExists(dirname(self::rutaArchivo()));
        File::put(self::rutaArchivo(), $bytes);
        File::put(self::rutaFicha(), Crypt::encryptString(json_encode($ficha)));

        unset($ficha['clave']);

        return $ficha;
    }

    /**
     * Quita el guardado.
     *
     * No es «dejar de emitir»: si el `.env` tiene uno puesto, vuelve a mandar
     * ése. Es deshacer la subida, no borrar la firma de la empresa.
     */
    public static function olvidar(): void
    {
        File::delete([self::rutaArchivo(), self::rutaFicha()]);
    }

    /** @return array<string, mixed>|null */
    private static function ficha(): ?array
    {
        if (! self::hay()) {
            return null;
        }

        try {
            return json_decode(Crypt::decryptString(File::get(self::rutaFicha())), true);
        } catch (\Throwable) {
            // Una ficha que no se puede descifrar es una `APP_KEY` cambiada. No
            // se inventa nada: se dice que no hay.
            return null;
        }
    }
}
