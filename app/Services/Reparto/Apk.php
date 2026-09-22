<?php

namespace App\Services\Reparto;

/**
 * El APK que se reparte, que es el más nuevo de los que haya publicados.
 *
 * ## Por qué no se deduce de `VERSION`
 *
 * `VERSION` dice qué versión tiene el **servidor**, y el servidor se despliega
 * con `bin/deploy.sh` mientras que el APK se compila aquí y se sube aparte con
 * `bin/publicar-apk.sh`. Entre una cosa y la otra pasan minutos, y durante esos
 * minutos `VERSION` promete un archivo que todavía no está. Por eso la versión
 * que se ofrece sale **del nombre del archivo que existe**, no de la del
 * servidor: se ofrece lo que se puede entregar.
 *
 * ## Dónde viven
 *
 * En `storage/app/private/apk`, fuera de `public/` y fuera de git. Fuera de
 * `public/` a propósito: así la entrega pasa por una ruta nuestra —que sabe
 * cuál es el más nuevo y le pone el tipo MIME que Android espera— en vez de
 * depender de cómo tenga configurado Apache el listado de directorios. Y fuera
 * del tar de `deploy.sh`, que no toca `storage/`: desplegar el servidor no se
 * lleva por delante lo publicado.
 *
 * No se borra ninguno al subir el siguiente. Ocupan 5 MB y tener el anterior a
 * mano es lo que permite volver atrás cuando una versión sale mala.
 */
class Apk
{
    /** El nombre que escribe `mobile/build-apk.sh`. */
    private const PATRON = 'venta-softland-*.apk';

    public static function carpeta(): string
    {
        return storage_path('app/private/apk');
    }

    /**
     * El más nuevo, o `null` si todavía no se ha publicado ninguno.
     *
     * @return array{ruta: string, nombre: string, version: string, bytes: int}|null
     */
    public function ultimo(): ?array
    {
        $mejor = null;

        foreach (glob(self::carpeta().DIRECTORY_SEPARATOR.self::PATRON) ?: [] as $ruta) {
            $nombre = basename($ruta);

            if (! preg_match('/^venta-softland-(\d+\.\d+\.\d+)\.apk$/', $nombre, $m)) {
                continue;
            }

            // Por versión, no por fecha del archivo: copiar un APK viejo al
            // servidor le pone fecha de hoy y lo haría pasar por el último.
            if ($mejor === null || version_compare($m[1], $mejor['version'], '>')) {
                $mejor = ['ruta' => $ruta, 'nombre' => $nombre, 'version' => $m[1], 'bytes' => (int) filesize($ruta)];
            }
        }

        return $mejor;
    }

    /**
     * Lo que se le cuenta al teléfono y a la pantalla de estado.
     *
     * @return array{version: string, bytes: int}|null
     */
    public function resumen(): ?array
    {
        $a = $this->ultimo();

        return $a ? ['version' => $a['version'], 'bytes' => $a['bytes']] : null;
    }
}
