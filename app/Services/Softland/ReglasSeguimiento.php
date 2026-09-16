<?php

namespace App\Services\Softland;

use Illuminate\Support\Facades\DB;

/**
 * Cómo hace seguimiento esta empresa.
 *
 * ## Qué es un compromiso, y qué no
 *
 * Un compromiso es **una promesa: un verbo y una fecha**. Llamar el martes.
 * Visitar el jueves. No dice nada de cuán cerca está el cierre, y no tiene por
 * qué: se puede estar al 90 % y que el próximo paso sea una llamada.
 *
 * Softland lo guarda en `nwtsegui.TipComp`, contra el maestro `nwttcomp`. La
 * app **no interpreta esos códigos**: los lee y los muestra. Si una empresa los
 * llenó con porcentajes —como hizo INNOVAGES, que no tenía otro sitio donde
 * poner el avance—, salen porcentajes; si los llenó con verbos, salen verbos.
 *
 * ## Por qué hay una lista de códigos vigentes
 *
 * Porque los códigos viejos no se borran: las 34 anotaciones de 2022 apuntan a
 * ellos y `nwtsegui` tiene clave foránea al maestro. Pero ofrecerlos en el
 * mismo desplegable que los nuevos deja al vendedor eligiendo entre «Llamar» y
 * «30 % - Se envía CTZ», que responden a preguntas distintas.
 *
 * Así que la configuración dice **cuáles se ofrecen**. Vacía, se ofrecen todos:
 * una instalación nueva funciona sin configurar nada, que es la regla de
 * siempre.
 */
class ReglasSeguimiento
{
    public const CLAVE = 'seguimiento';

    /** La escalera de avance por omisión: de 10 en 10, sin 0 ni 100. */
    public const AVANCE = [10, 20, 30, 40, 50, 60, 70, 80, 90];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return array<string, mixed> */
    public function valores(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $json = DB::connection('softland')->table('ventas.config')
            ->where('clave', self::CLAVE)->value('valor');

        $datos = $json ? json_decode($json, true) : [];

        return self::$cache = is_array($datos) ? $datos : [];
    }

    public function guardar(array $datos): void
    {
        DB::connection('softland')->table('ventas.config')->updateOrInsert(
            ['clave' => self::CLAVE],
            ['valor' => json_encode($datos + $this->valores(), JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
        );

        self::$cache = null;
    }

    /**
     * Los compromisos que se ofrecen, en orden.
     *
     * @return list<array{codigo: string, nombre: string}>
     */
    public function compromisos(): array
    {
        $vigentes = array_map('strval', (array) ($this->valores()['compromisos'] ?? []));

        $filas = DB::connection('softland')->table('softland.nwttcomp')
            ->orderBy('descomp')->get(['codcomp', 'descomp']);

        return $filas
            ->map(fn ($f) => ['codigo' => trim((string) $f->codcomp), 'nombre' => trim((string) $f->descomp)])
            ->filter(fn ($c) => $vigentes === [] || in_array($c['codigo'], $vigentes, true))
            ->values()->all();
    }

    /**
     * El compromiso que la app anota sola al crear una cotización.
     *
     * No se le pregunta al vendedor porque **ya ocurrió**: crear la cotización
     * es «enviar la propuesta, hoy». Un campo obligatorio que siempre viene
     * relleno se convierte en un campo que nadie lee; esto no es un campo, es
     * el registro de un hecho.
     */
    public function compromisoInicial(): ?string
    {
        return $this->codigo('inicial');
    }

    /** El que se propone para el próximo paso, que sí elige el vendedor. */
    public function compromisoPorOmision(): ?string
    {
        return $this->codigo('por_omision');
    }

    /**
     * El que se anota cuando el documento sale de verdad hacia el cliente.
     *
     * La app sabe cuándo se entrega un documento —lleva el acuse de la emisión—
     * así que puede dejar la historia real del contacto sin que nadie teclee.
     */
    public function compromisoEntrega(): ?string
    {
        return $this->codigo('entrega');
    }

    /** La escalera de avance de esta empresa. */
    public function avance(): array
    {
        $escalera = array_values(array_filter(
            array_map('intval', (array) ($this->valores()['avance'] ?? [])),
            fn ($p) => $p > 0 && $p < 100,
        ));

        return $escalera ?: self::AVANCE;
    }

    /**
     * Cuántos minutos dura en el calendario un compromiso de este tipo.
     *
     * Media hora sirve para una llamada; una visita nunca dura media hora, y un
     * evento que miente sobre su duración hace que el resto del día del
     * vendedor esté mal planificado.
     */
    public function duracionMinutos(?string $codigo): int
    {
        $mapa = (array) ($this->valores()['duracion'] ?? []);

        return (int) ($mapa[$codigo] ?? ($codigo === 'VIS' ? 60 : 30));
    }

    /** Un código configurado, sólo si sigue existiendo en el maestro. */
    private function codigo(string $clave): ?string
    {
        $codigo = trim((string) ($this->valores()[$clave] ?? ''));

        if ($codigo === '') {
            return null;
        }

        return DB::connection('softland')->table('softland.nwttcomp')
            ->where('codcomp', $codigo)->exists() ? $codigo : null;
    }

    public static function olvidar(): void
    {
        self::$cache = null;
    }
}
