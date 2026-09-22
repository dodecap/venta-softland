<?php

namespace App\Services\Actualizacion;

use App\Services\Reparto\Apk;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PharData;
use RuntimeException;

/**
 * Traer una versión nueva desde GitHub y ponerla en este servidor.
 *
 * ## Lo que se baja, y por qué el `vendor` viaja dentro
 *
 * La publicación trae el código (1,4 MB), el APK (5 MB) y las dependencias
 * (17 MB comprimidas). Las dependencias van empaquetadas en vez de correr
 * `composer install` en casa del cliente por dos razones: **lo que se probó
 * aquí es lo que corre allá** —un `composer install` en otra fecha puede
 * resolver otra cosa— y porque el servidor de un cliente puede tener
 * packagist.org cortado por el cortafuegos, y entonces la actualización se
 * queda a medias en el peor sitio posible.
 *
 * Y no se bajan siempre: el nombre del paquete lleva dentro el sha256 del
 * `composer.lock` con el que se construyó. Si coincide con el de aquí, las
 * dependencias no han cambiado y esos 17 MB se saltan. La versión típica pesa
 * entonces 6,4 MB.
 *
 * ## Lo que NO se toca
 *
 * El paquete trae exactamente las mismas carpetas que `bin/deploy.sh`, que son
 * las del código. Nada de `.env`, nada de `storage/`: ahí viven la conexión
 * cifrada, el certificado del DTE, los PDF y los APK publicados. Actualizar no
 * puede llevarse por delante lo que identifica a esta instalación, que es lo
 * único irrecuperable.
 *
 * ## Se descomprime encima, como el despliegue
 *
 * Es lo que `bin/deploy.sh` lleva haciendo todo el proyecto contra este mismo
 * servidor: se escribe encima del árbol vivo. Un archivo que el paquete ya no
 * traiga se queda —igual que al desplegar—, y por eso quitar un archivo del
 * repositorio no basta para que desaparezca de un servidor actualizado; si
 * alguna vez hace falta, se quita con una migración.
 *
 * Nunca se actualiza solo. Alguien lo manda, desde la app o desde la consola.
 */
class Actualizador
{
    /**
     * @param  string|null  $raiz  Dónde se descomprime. Se puede cambiar para
     *                             poder probar el camino del error sin escribir
     *                             encima del árbol de verdad.
     */
    public function __construct(private ?string $raiz = null) {}

    private function raiz(): string
    {
        return $this->raiz ?? base_path();
    }

    /**
     * ¿Hay algo nuevo?
     *
     * @return array{version: string, publicada: ?string, hay: bool, nueva: ?array, problema: ?string}
     */
    public function comprobar(): array
    {
        $mia = (string) config('app.version');

        try {
            $p = Publicacion::ultima();
        } catch (\Throwable $e) {
            return ['version' => $mia, 'publicada' => null, 'hay' => false, 'nueva' => null,
                'problema' => $e->getMessage()];
        }

        if (! $p) {
            return ['version' => $mia, 'publicada' => null, 'hay' => false, 'nueva' => null,
                'problema' => null];
        }

        return [
            'version' => $mia,
            'publicada' => $p->version(),
            'hay' => $p->esMasNuevaQue($mia),
            'nueva' => $p->resumen(),
            'problema' => null,
        ];
    }

    /**
     * Bajar, comprobar y aplicar una publicación.
     *
     * `$decir` recibe cada paso para que la consola lo cuente; la pantalla lo
     * lee después del archivo de estado.
     *
     * @return array{version: string, pasos: array<int, string>}
     */
    public function aplicar(Publicacion $p, ?callable $decir = null): array
    {
        $pasos = [];
        $paso = function (string $texto) use (&$pasos, $decir, $p) {
            $pasos[] = $texto;
            $this->anotar(['estado' => 'trabajando', 'version' => $p->version(), 'paso' => $texto]);
            if ($decir) {
                $decir($texto);
            }
        };

        $codigo = $p->pieza('servidor');

        if (! $codigo) {
            throw new RuntimeException(
                'La versión '.$p->version().' no trae el paquete del servidor. '
                .'Está publicada a medias: no se puede instalar.'
            );
        }

        $temporal = storage_path('app/private/actualizacion-'.$p->version());
        File::ensureDirectoryExists($temporal);

        try {
            // 1) Bajar primero, todo, y comprobarlo. Nada se escribe sobre el
            //    árbol vivo hasta que está entero y cuadra: media descarga
            //    aplicada es un servidor que no arranca.
            $paso('Bajando el código ('.$this->pesa($codigo['bytes']).')');
            $tarCodigo = $this->bajar($codigo, $temporal);

            $vendor = $p->pieza('vendor');
            $tarVendor = null;

            if ($vendor && $this->cambianLasDependencias($vendor)) {
                $paso('Bajando las dependencias ('.$this->pesa($vendor['bytes']).')');
                $tarVendor = $this->bajar($vendor, $temporal);
            } elseif ($vendor) {
                $paso('Las dependencias no han cambiado: '.$this->pesa($vendor['bytes']).' que no hay que bajar');
            }

            $apk = $p->pieza('apk');
            $rutaApk = null;

            if ($apk) {
                $paso('Bajando la app ('.$this->pesa($apk['bytes']).')');
                $rutaApk = $this->bajar($apk, $temporal);
            }

            // 2) Y ahora sí, escribir. Las dependencias antes que el código: si
            //    la versión nueva usa algo que la vieja no tenía, tiene que
            //    estar ya ahí cuando el código nuevo aterrice.
            if ($tarVendor) {
                $paso('Instalando las dependencias');
                $this->descomprimir($tarVendor, $this->raiz());
            }

            $paso('Instalando el código');
            $this->descomprimir($tarCodigo, $this->raiz());

            if ($rutaApk) {
                $paso('Guardando la app para repartirla');
                File::ensureDirectoryExists(Apk::carpeta());
                File::move($rutaApk, Apk::carpeta().DIRECTORY_SEPARATOR.$apk['nombre'], true);
            }

            // 3) Rematar, a ser posible en un proceso nuevo. Éste lleva en
            //    memoria el autoload y el código de la versión **anterior**:
            //    si la nueva estrena un paquete, el cargador que hay aquí
            //    dentro no sabe encontrarlo.
            $paso('Poniendo al día la base de datos');
            $this->rematar(['migrate --force']);

            $paso('Recompilando');
            $this->rematar(['config:clear', 'view:cache']);

            $this->anotar(['estado' => 'listo', 'version' => $p->version(), 'paso' => 'Actualizado a '.$p->version()]);

            return ['version' => $p->version(), 'pasos' => $pasos];
        } catch (\Throwable $e) {
            $this->anotar([
                'estado' => 'error',
                'version' => $p->version(),
                'paso' => end($pasos) ?: 'Empezando',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            File::deleteDirectory($temporal);
        }
    }

    /**
     * ¿Hay que bajarse las dependencias?
     *
     * El nombre del paquete es `vendor-<sha256 del composer.lock>.tgz`. Se
     * compara con el de aquí: si es el mismo, el `vendor` que hay puesto ya es
     * el que le toca a la versión nueva.
     */
    private function cambianLasDependencias(array $vendor): bool
    {
        $lock = $this->raiz().DIRECTORY_SEPARATOR.'composer.lock';

        if (! $vendor['marca'] || ! is_file($lock) || ! is_dir($this->raiz().DIRECTORY_SEPARATOR.'vendor')) {
            return true;
        }

        return ! str_starts_with(hash_file('sha256', $lock), $vendor['marca']);
    }

    /** Bajar una pieza y comprobar que llegó entera. */
    private function bajar(array $pieza, string $carpeta): string
    {
        $destino = $carpeta.DIRECTORY_SEPARATOR.$pieza['nombre'];

        $r = Http::timeout((int) config('actualizacion.plazo'))
            ->withHeaders(['User-Agent' => 'venta-softland/'.config('app.version')])
            ->sink($destino)
            ->get($pieza['url']);

        if (! $r->successful()) {
            throw new RuntimeException('No se pudo bajar «'.$pieza['nombre'].'»: GitHub contestó '.$r->status().'.');
        }

        // El sha256 lo calcula GitHub al subir el archivo y lo sirve junto al
        // enlace. Comprobarlo cuesta un segundo y descarta de una vez la
        // descarga cortada, el proxy que devuelve una página de error con
        // código 200 y el archivo cambiado por el camino.
        if ($pieza['sha256']) {
            $mio = hash_file('sha256', $destino);

            if (! hash_equals($pieza['sha256'], $mio)) {
                throw new RuntimeException(
                    '«'.$pieza['nombre'].'» no llegó entero: se esperaba '.substr($pieza['sha256'], 0, 12)
                    .'… y llegó '.substr($mio, 0, 12).'…'
                );
            }
        }

        return $destino;
    }

    /**
     * Descomprimir encima del árbol.
     *
     * Con `PharData` y no llamando a `tar`: así funciona igual aunque el
     * servidor tenga `exec` desactivado, que es una configuración razonable en
     * un servidor de producción y no algo que podamos exigirle a un cliente.
     * `phar.readonly=1` no estorba — prohíbe *escribir* archivos phar, no
     * leerlos.
     */
    private function descomprimir(string $tar, string $destino): void
    {
        // El paquete se construye sin la entrada «.» a propósito: `PharData`
        // se niega a extraerla («Cannot extract "."»). Ver bin/publicar-version.sh.
        (new PharData($tar))->extractTo($destino, null, true);
    }

    /**
     * Los últimos comandos, en un PHP recién arrancado si se puede.
     *
     * Arrancado de nuevo porque lo que hay cargado en este proceso es la
     * versión vieja: el cargador de clases de Composer se construye una vez,
     * al principio, y no se entera de los paquetes que acaba de traer la
     * versión nueva.
     *
     * Si el servidor tiene `exec` desactivado —que es una configuración
     * razonable y no algo que podamos exigirle a un cliente— se hace aquí
     * mismo. Funciona igual salvo en el caso raro de que la versión nueva
     * estrene una dependencia; para ése queda dicho en la pantalla que hay que
     * rematar desde la consola.
     */
    private function rematar(array $comandos): void
    {
        $php = PHP_BINARY;
        $puede = function_exists('exec')
            && ! in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)
            && is_file($php);

        foreach ($comandos as $comando) {
            if (! $puede) {
                Artisan::call($comando);

                continue;
            }

            $salida = [];
            $codigo = 0;

            // `chdir` y no un `cd` dentro de la orden: en Windows `cd` sin
            // `/d` no cambia de unidad, y una instalación en D: se quedaría
            // ejecutando artisan desde otro sitio sin decir nada.
            $antes = getcwd();
            chdir($this->raiz());

            try {
                exec(escapeshellarg($php).' artisan '.$comando.' 2>&1', $salida, $codigo);
            } finally {
                if ($antes !== false) {
                    chdir($antes);
                }
            }

            if ($codigo !== 0) {
                throw new RuntimeException(
                    'Falló «php artisan '.$comando.'»: '.trim(implode(' ', array_slice($salida, -5)))
                );
            }
        }
    }

    private function pesa(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }

    /* ---- El archivo de estado ------------------------------------------- */

    public static function rutaEstado(): string
    {
        return storage_path('app/private/actualizacion.json');
    }

    /** Lo último que se supo de una actualización, o `null` si nunca hubo. */
    public static function estado(): ?array
    {
        $ruta = self::rutaEstado();

        if (! is_file($ruta)) {
            return null;
        }

        $d = json_decode((string) file_get_contents($ruta), true);

        return is_array($d) ? $d : null;
    }

    /**
     * Se anota en un archivo y no en la base: si la actualización deja el
     * servidor a medias, el archivo sigue ahí y dice por dónde se quedó.
     */
    private function anotar(array $estado): void
    {
        File::ensureDirectoryExists(dirname(self::rutaEstado()));

        file_put_contents(self::rutaEstado(), json_encode(
            $estado + ['error' => null, 'en' => now()->toIso8601String()],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
    }
}
