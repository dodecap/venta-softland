<?php

namespace App\Services\Actualizacion;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La última versión publicada en GitHub, mirada desde el servidor de un cliente.
 *
 * ## Por qué desde el servidor y no desde el teléfono
 *
 * El teléfono ya sabe si hay un APK más nuevo —se lo dice `/ping`, y la
 * campanita avisa—, pero el APK no es lo único que caduca: un APK nuevo contra
 * una API vieja desfasa igual. Quien tiene que enterarse de que hay versión
 * nueva del **servidor** es el servidor, que es el que puede hacer algo al
 * respecto.
 *
 * ## Qué hace falta para que esto funcione en casa de un cliente
 *
 * Nada. El repositorio es público, así que no hay ninguna llave que repartir ni
 * que rotar. Si algún día dejara de serlo, aquí haría falta un token por
 * instalación y eso es exactamente lo que no queremos: un secreto más que
 * caduca en el servidor de otra persona.
 *
 * La publicación se mira, no se aplica: aplicarla es de `Actualizador`, y
 * siempre la manda una persona.
 */
class Publicacion
{
    /** @param array<string, mixed> $datos La respuesta cruda de la API. */
    private function __construct(private array $datos) {}

    /**
     * La última publicación, o `null` si no hay ninguna.
     *
     * `null` no es un error: un repositorio recién bifurcado no tiene
     * publicaciones y eso no impide que el servidor siga funcionando.
     */
    public static function ultima(): ?self
    {
        return self::traer('releases/latest');
    }

    /**
     * Una versión concreta, por su etiqueta.
     *
     * Existe para poder **volver atrás**. Una versión que sale mala en casa de
     * un cliente no se arregla esperando a la siguiente: se le vuelve a poner
     * la anterior, que sigue publicada, con el mismo camino por el que llegó
     * la mala. Sin esto, actualizar sería una puerta de una sola dirección.
     */
    public static function deVersion(string $version): ?self
    {
        return self::traer('releases/tags/v'.ltrim($version, 'vV'));
    }

    private static function traer(string $ruta): ?self
    {
        $api = rtrim((string) config('actualizacion.api'), '/');
        $repo = trim((string) config('actualizacion.repositorio'), '/');

        $r = Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            // GitHub rechaza sin User-Agent, y con uno propio los registros de
            // la API dicen quién pregunta.
            'User-Agent' => 'venta-softland/'.config('app.version'),
        ])->timeout(20)->get($api.'/repos/'.$repo.'/'.$ruta);

        if ($r->status() === 404) {
            return null;
        }

        if (! $r->successful()) {
            throw new RuntimeException(
                'GitHub contestó '.$r->status().' al preguntar por la última versión de «'.$repo.'».'
            );
        }

        $datos = $r->json();

        if (! is_array($datos) || ! isset($datos['tag_name'])) {
            throw new RuntimeException('La respuesta de GitHub no trae ninguna versión.');
        }

        // Un borrador no es una publicación: se ve con token y no se baja sin
        // él. Que no se cuele como «versión nueva» que nadie puede instalar.
        if (! empty($datos['draft'])) {
            return null;
        }

        return new self($datos);
    }

    /** La etiqueta es `v0.47.0`; la versión, `0.47.0`. */
    public function version(): string
    {
        return ltrim((string) $this->datos['tag_name'], 'vV');
    }

    public function notas(): string
    {
        return trim((string) ($this->datos['body'] ?? ''));
    }

    public function publicadaEn(): ?string
    {
        return $this->datos['published_at'] ?? null;
    }

    public function esMasNuevaQue(string $version): bool
    {
        return version_compare($this->version(), $version, '>');
    }

    /**
     * Una pieza de la publicación, por su papel: `servidor`, `apk` o `vendor`.
     *
     * Se busca por cómo se llama el archivo y no por su orden: GitHub los
     * devuelve como quiere, y una publicación puede traer piezas de más.
     *
     * @return array{nombre: string, bytes: int, url: string, sha256: ?string, marca: ?string}|null
     */
    public function pieza(string $cual): ?array
    {
        $patron = (string) config('actualizacion.piezas.'.$cual);

        foreach ($this->datos['assets'] ?? [] as $a) {
            $nombre = (string) ($a['name'] ?? '');
            $marca = null;

            if (str_starts_with($patron, '/')) {
                if (! preg_match($patron, $nombre, $m)) {
                    continue;
                }
                $marca = $m[1] ?? null;
            } elseif (! str_ends_with($nombre, $patron)) {
                continue;
            }

            return [
                'nombre' => $nombre,
                'bytes' => (int) ($a['size'] ?? 0),
                'url' => (string) ($a['browser_download_url'] ?? ''),
                // GitHub calcula el sha256 de cada archivo al subirlo y lo
                // sirve aquí. Por eso no publicamos un manifiesto aparte: sería
                // otro archivo que puede mentir, firmado por nadie.
                'sha256' => self::sha256De($a['digest'] ?? null),
                'marca' => $marca,
            ];
        }

        return null;
    }

    /** `sha256:abc…` → `abc…`. Las publicaciones viejas no lo traen. */
    private static function sha256De(mixed $digest): ?string
    {
        if (! is_string($digest) || ! str_starts_with($digest, 'sha256:')) {
            return null;
        }

        return substr($digest, 7);
    }

    /** Lo que se le cuenta a la pantalla de Configuración. */
    public function resumen(): array
    {
        return [
            'version' => $this->version(),
            'notas' => $this->notas(),
            'publicada_en' => $this->publicadaEn(),
            'piezas' => array_filter([
                'servidor' => $this->pieza('servidor'),
                'apk' => $this->pieza('apk'),
                'vendor' => $this->pieza('vendor'),
            ]),
        ];
    }
}
