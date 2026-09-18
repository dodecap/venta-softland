<?php

namespace App\Services\Sii;

use App\Support\Rut;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * La ficha que el SII publica de un RUT, lista para llenar el formulario.
 *
 * Único sitio que conoce la dirección y la llave de `sii-aux`, y único que sale
 * a internet. Lo de después —convertir el texto en códigos— es de
 * {@see Traduccion}; aquí sólo se consulta, se guarda y se compone.
 *
 * ## El teléfono no llama a esta API
 *
 * La llama el servidor. La llave no puede viajar en el APK, que se descompila;
 * el servidor es además el único que puede traducir, porque es quien tiene los
 * maestros; y así, si la API cambia, se arregla en un sitio y sin repartir una
 * versión nueva.
 *
 * ## La llave viaja en la URL, así que la URL no se escribe en ninguna parte
 *
 * La API de hoy recibe `?key=…`, de modo que la dirección completa **es** un
 * secreto. Por eso aquí no se llama nunca a `$respuesta->throw()`: la excepción
 * que lanza Laravel lleva la URL dentro, y acabaría escrita en `laravel.log` en
 * cuanto fallara una consulta. Los estados se miran a mano y los mensajes se
 * escriben aquí.
 *
 * ## Lo que se propone no es lo que se guarda
 *
 * Esto devuelve una **propuesta**: cada campo con su valor traducido, lo que el
 * SII dijo literalmente, y si hubo que recortarlo. Quien da de alta es el
 * `ClienteController` de siempre, con la persona de por medio. El padrón traerá
 * un dato viejo algún día y ese día hace falta que alguien estuviera mirando.
 */
class Auxiliar
{
    private const CONN = 'softland';

    private const TABLA = 'ventas.sii_auxiliar';

    public function __construct(private readonly Traduccion $traduccion = new Traduccion) {}

    /** ¿Hay dirección y llave en el `.env`? Si no, la pantalla no ofrece el botón. */
    public function configurado(): bool
    {
        return $this->url() !== '' && $this->llave() !== '';
    }

    /**
     * La ficha de un RUT: de la caché si está fresca, del SII si no.
     *
     * Se pide el RUT **entero, con su dígito verificador**, y se comprueba. No
     * es una validación de cortesía: `Rut::cuerpo()` no puede ser idempotente
     * —«76469596» es a la vez el cuerpo de 76.469.596-8 y el RUT 7.646.959-6
     * completo— así que un RUT ya reducido que llegue aquí se reduciría otra
     * vez y contestaríamos por otra empresa sin que nada fallara. Exigir el
     * dígito hace ruidoso ese error en vez de silencioso.
     *
     * @param  bool  $refrescar  salta la caché fresca y vuelve a preguntar
     *
     * @throws RuntimeException si no hay forma de contestar
     */
    public function consultar(string $rut, bool $refrescar = false): array
    {
        if (! $this->configurado()) {
            throw new RuntimeException('La consulta al SII no está configurada en este servidor.');
        }

        if (! Rut::esValido($rut)) {
            throw new RuntimeException('Ese RUT no tiene forma de RUT.');
        }

        $cuerpo = Rut::cuerpo($rut);

        $guardado = $this->guardado($cuerpo);

        if (! $refrescar && $guardado && $this->fresco($guardado)) {
            return $this->componer($cuerpo, $this->descodificar($guardado), $guardado->consultado_en, 'cache');
        }

        try {
            $sii = $this->pedir($cuerpo);
        } catch (RuntimeException $e) {
            // Si hay algo guardado, aunque esté viejo, vale más que un error:
            // el vendedor está de pie delante del cliente.
            if ($guardado) {
                return $this->componer($cuerpo, $this->descodificar($guardado), $guardado->consultado_en, 'cache-vieja');
            }

            throw $e;
        }

        $this->guardar($cuerpo, $sii);

        return $this->componer($cuerpo, $sii, now()->toDateTimeString(), 'sii');
    }

    // ------------------------------------------------------------ la consulta

    /**
     * @return array|null  `null` cuando el padrón no tiene ese RUT, que no es
     *                     un error: es una respuesta
     *
     * @throws RuntimeException
     */
    private function pedir(string $cuerpo): ?array
    {
        $rut = $cuerpo.'-'.Rut::dv($cuerpo);

        try {
            $respuesta = Http::connectTimeout(3)
                ->timeout(6)
                ->acceptJson()
                ->get($this->url(), ['rut' => $rut, 'key' => $this->llave()]);
        } catch (Throwable $e) {
            // El mensaje de Guzzle lleva la URL, y la URL lleva la llave.
            throw new RuntimeException('No se pudo llegar al servicio del SII.');
        }

        if ($respuesta->status() === 404) {
            return null;
        }

        if ($respuesta->status() === 401) {
            throw new RuntimeException('El servidor no tiene una llave válida para el servicio del SII.');
        }

        if (! $respuesta->successful()) {
            throw new RuntimeException('El servicio del SII respondió con un error ('.$respuesta->status().').');
        }

        $datos = $respuesta->json();

        if (! is_array($datos)) {
            throw new RuntimeException('El servicio del SII devolvió algo que no se entiende.');
        }

        return ($datos['encontrado'] ?? false) ? $datos : null;
    }

    // -------------------------------------------------------------- la caché

    private function guardado(string $cuerpo): ?object
    {
        return DB::connection(self::CONN)->table(self::TABLA)->where('rut', $cuerpo)->first();
    }

    private function fresco(object $fila): bool
    {
        $dias = $fila->encontrado
            ? (int) config('services.sii_aux.dias', 30)
            : (int) config('services.sii_aux.dias_sin_hallar', 7);

        return now()->diffInDays($fila->consultado_en, absolute: true) < $dias;
    }

    private function descodificar(object $fila): ?array
    {
        if (! $fila->encontrado || $fila->respuesta === null) {
            return null;
        }

        $datos = json_decode($fila->respuesta, true);

        return is_array($datos) ? $datos : null;
    }

    private function guardar(string $cuerpo, ?array $sii): void
    {
        DB::connection(self::CONN)->table(self::TABLA)->updateOrInsert(
            ['rut' => $cuerpo],
            [
                'encontrado' => $sii !== null,
                'respuesta' => $sii === null ? null : json_encode($sii, JSON_UNESCAPED_UNICODE),
                'consultado_en' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    // ------------------------------------------------------------ la propuesta

    /**
     * La ficha que se le manda al teléfono.
     *
     * Cada campo lleva tres cosas: el **valor** que va al formulario —ya
     * traducido a código donde toca—, el **texto** que dijo el SII, y si hizo
     * falta recortarlo. El texto no es decoración: cuando el código no se pudo
     * resolver, es lo único que el vendedor tiene para elegir a mano, y cuando
     * sí se pudo, es lo que le deja comprobar que la traducción acertó.
     */
    private function componer(string $cuerpo, ?array $sii, string $consultadoEn, string $fuente): array
    {
        $base = [
            'rut' => $cuerpo,
            'dv' => Rut::dv($cuerpo),
            'rut_formateado' => Rut::formatear($cuerpo.Rut::dv($cuerpo)),
            'consultado_en' => $consultadoEn,
            'fuente' => $fuente,
        ];

        if ($sii === null) {
            return $base + ['encontrado' => false, 'campos' => [], 'giros' => []];
        }

        $nombre = $this->limpiar($sii['razon_social'] ?? null);
        $comuna = $this->traduccion->comuna($sii['comuna'] ?? null);
        $ciudadSii = $this->limpiar($sii['ciudad'] ?? null);
        $ciudad = $this->traduccion->ciudad($sii['ciudad'] ?? null, $comuna);
        $giroTexto = $this->limpiar($sii['giro'] ?? null);

        return $base + [
            'encontrado' => true,
            'campos' => [
                'nombre' => $this->recortado($nombre, 60),
                'direccion' => $this->recortado($this->limpiar($sii['direccion'] ?? null), 60),
                'comuna' => [
                    'valor' => $comuna,
                    'texto' => $this->limpiar($sii['comuna'] ?? null),
                ],
                'ciudad' => [
                    'valor' => $ciudad,
                    'texto' => $ciudadSii,
                    // Sin ciudad en el padrón —pasa en un tercio de las fichas—
                    // sale la de la comuna. Que se sepa de dónde vino.
                    'deducido' => $ciudad !== null && $ciudadSii === null,
                ],
                'giro' => [
                    'valor' => $this->traduccion->giro($sii['giro_codigo'] ?? null),
                    'texto' => $giroTexto,
                    'acteco' => Traduccion::acteco($sii['giro_codigo'] ?? null),
                ],
                'email_dte' => ['valor' => $this->limpiar($sii['correo_dte'] ?? null), 'texto' => null],
            ],
            'giros' => $this->giros($sii),
            'region' => $this->limpiar($sii['region'] ?? null),
            'padron' => $sii['fecha_actualizacion'] ?? null,
        ];
    }

    /**
     * Los giros declarados de la empresa, para que el vendedor elija cuál le
     * vende. `giros_todos_detalle` es lo que trae el código; `giros_todos`, la
     * versión sólo-texto que la API mantiene por compatibilidad y que aquí no
     * sirve.
     *
     * @return list<array{acteco: ?string, descripcion: string, valor: ?string}>
     */
    private function giros(array $sii): array
    {
        $giros = [];

        foreach ($sii['giros_todos_detalle'] ?? [] as $giro) {
            $acteco = Traduccion::acteco($giro['codigo'] ?? null);

            $giros[] = [
                'acteco' => $acteco,
                'descripcion' => $this->limpiar($giro['descripcion'] ?? null) ?? '',
                'valor' => $this->traduccion->giro($acteco),
            ];
        }

        return $giros;
    }

    /**
     * El valor con el recorte a la vista.
     *
     * El SII publica razones sociales de hasta 80 caracteres y `NomAux` admite
     * 60. Recortar es obligatorio; **recortar sin decirlo** es lo que no se
     * puede hacer, porque el nombre queda cortado a media palabra y nadie sabe
     * que faltaba algo.
     */
    private function recortado(?string $texto, int $tope): array
    {
        if ($texto === null) {
            return ['valor' => null, 'texto' => null, 'recortado' => false];
        }

        $valor = Traduccion::recortar($texto, $tope);

        return [
            'valor' => $valor,
            'texto' => $texto,
            'recortado' => $valor !== $texto,
        ];
    }

    /** Sin espacios dobles, y el vacío es `null`: el padrón usa los dos. */
    private function limpiar(?string $texto): ?string
    {
        $texto = trim(preg_replace('/\s+/', ' ', (string) $texto));

        return $texto === '' ? null : $texto;
    }

    private function url(): string
    {
        return trim((string) config('services.sii_aux.url'));
    }

    private function llave(): string
    {
        return trim((string) config('services.sii_aux.key'));
    }
}
