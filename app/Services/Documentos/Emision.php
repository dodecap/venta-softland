<?php

namespace App\Services\Documentos;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Congela lo que se le entregó al cliente.
 *
 * ## La regla
 *
 * Una versión que **ya salió** no se toca nunca más. Corregir un documento
 * emitido no reescribe su archivo: crea la versión siguiente, y la anterior
 * queda con su fecha y su hash. Una versión que se generó y nunca se envió — la
 * vista previa, el vendedor que miró y cerró la pantalla — sí se reemplaza:
 * nadie la tiene, y guardar cien previas de la misma cotización sólo llena el
 * disco.
 *
 * De ahí sale gratis la respuesta a la pregunta incómoda: si el cliente llama
 * con el PDF en la mano y el vendedor corrigió el documento después, los dos
 * números distintos están los dos guardados, con fecha y con quién.
 */
class Emision
{
    public function __construct(private Motor $motor) {}

    /**
     * Dibuja el documento y lo deja guardado.
     *
     * @return array{pdf: string, version: int, hash: string, archivo: string}
     */
    public function emitir(TipoDocumento $tipo, int $numero, array $datos, ?Usuario $u = null): array
    {
        $contexto = $this->motor->contexto($tipo, $datos);

        /*
         * La huella es del **HTML**, no del PDF.
         *
         * Dompdf estampa la fecha de creación dentro del archivo: dos PDF del
         * mismo documento generados con un segundo de diferencia tienen bytes
         * distintos, y un hash de los bytes no serviría para decidir si algo
         * cambió — que es justo para lo que se usa abajo. El HTML sí: si dice lo
         * mismo, es el mismo documento.
         */
        $html = $this->motor->htmlDe($contexto);
        $hash = hash('sha256', $html);
        $pdf = $this->motor->dibujar($html);

        $ultima = $this->ultima($tipo, $numero);

        /*
         * Mismo documento, misma versión: no hay nada que versionar.
         *
         * Sin esto, abrir la vista previa de una cotización ya entregada
         * fabricaría una versión nueva cada vez, con su archivo, sin que el
         * documento hubiera cambiado en nada.
         */
        if ($ultima && $ultima->hash === $hash && File::exists(storage_path('app/private/'.$ultima->archivo))) {
            return [
                'pdf' => $pdf,
                'version' => (int) $ultima->version,
                'hash' => $hash,
                'archivo' => $ultima->archivo,
            ];
        }

        // Si la última versión nunca salió, se pisa. Sólo lo entregado es
        // intocable; un borrador que nadie vio no es historia de nada.
        $reemplaza = $ultima && $ultima->enviado_at === null;
        $version = $reemplaza ? (int) $ultima->version : (($ultima->version ?? 0) + 1);

        $archivo = 'documentos/'.$tipo->value.'/'.$numero.'-v'.$version.'.pdf';
        $ruta = storage_path('app/private/'.$archivo);
        File::ensureDirectoryExists(dirname($ruta));
        File::put($ruta, $pdf);

        $fila = [
            'hash' => $hash,
            'datos' => json_encode($this->paraGuardar($contexto), JSON_UNESCAPED_UNICODE),
            'archivo' => $archivo,
            'bytes' => strlen($pdf),
            'emitido_at' => now(),
            'usuario_id' => $u?->id,
            'updated_at' => now(),
        ];

        if ($reemplaza) {
            $this->tabla()->where('id', $ultima->id)->update($fila);
        } else {
            $this->tabla()->insert($fila + [
                'tipo' => $tipo->value,
                'numero' => $numero,
                'version' => $version,
                'created_at' => now(),
            ]);
        }

        return ['pdf' => $pdf, 'version' => $version, 'hash' => $hash, 'archivo' => $archivo];
    }

    /**
     * Deja constancia de que el documento salió, y con eso lo vuelve inmutable.
     *
     * A partir de aquí, una corrección del documento genera una versión nueva.
     */
    public function marcarEnviado(TipoDocumento $tipo, int $numero, string $canal): void
    {
        $ultima = $this->ultima($tipo, $numero);
        if (! $ultima || $ultima->enviado_at !== null) {
            return;
        }

        $this->tabla()->where('id', $ultima->id)->update([
            'enviado_at' => now(),
            'canal' => $canal,
            'updated_at' => now(),
        ]);
    }

    /** La última versión, la que se muestra y la que se manda. */
    public function ultima(TipoDocumento $tipo, int $numero): ?object
    {
        return $this->tabla()
            ->where('tipo', $tipo->value)
            ->where('numero', $numero)
            ->orderByDesc('version')
            ->first();
    }

    /** El historial, para responder «qué le mandamos y cuándo». */
    public function historial(TipoDocumento $tipo, int $numero): array
    {
        return $this->tabla()
            ->where('tipo', $tipo->value)
            ->where('numero', $numero)
            ->orderBy('version')
            ->get(['version', 'hash', 'bytes', 'emitido_at', 'enviado_at', 'canal', 'usuario_id'])
            ->map(fn ($e) => [
                'version' => (int) $e->version,
                'hash' => substr($e->hash, 0, 12),
                'bytes' => (int) $e->bytes,
                'emitido' => $e->emitido_at,
                'enviado' => $e->enviado_at,
                'canal' => $e->canal,
            ])->all();
    }

    /** Los bytes de una versión guardada, si el archivo sigue estando. */
    public function bytes(object $emision): ?string
    {
        $ruta = storage_path('app/private/'.$emision->archivo);

        return File::exists($ruta) ? File::get($ruta) : null;
    }

    /**
     * Tira las versiones de un documento que se eliminó de Softland.
     *
     * Sólo se llega aquí cuando el documento **nunca salió** — una emisión con
     * `enviado_at` impide eliminar, y por eso lo que se borra aquí no es «lo
     * entregado al cliente» sino previas que ya no tienen documento detrás.
     * Dejarlas sería peor que borrarlas: el número se reparte de nuevo, y la
     * versión 3 de la cotización vieja aparecería como historial de la nueva.
     */
    public function borrar(TipoDocumento $tipo, int $numero): int
    {
        $versiones = $this->tabla()
            ->where('tipo', $tipo->value)->where('numero', $numero)
            ->get(['id', 'archivo']);

        foreach ($versiones as $v) {
            File::delete(storage_path('app/private/'.$v->archivo));
        }

        return $this->tabla()->where('tipo', $tipo->value)->where('numero', $numero)->delete();
    }

    private function tabla()
    {
        return DB::connection('softland')->table('ventas.documento_emision');
    }

    /**
     * Qué del contexto se guarda.
     *
     * Dos cosas quedan fuera. El tipo documental, porque es una instancia de
     * enum y al deserializarla volvería como un string que no calza con nada. Y
     * el logo, que son 30 KB de base64 por emisión: ya está dibujado dentro del
     * PDF, que es el snapshot de verdad, y guardarlo otra vez en JSON dobla el
     * disco sin agregar una sola respuesta que el archivo no dé.
     *
     * El resto sí: empresa, cliente, líneas, precios, impuestos, totales, UF del
     * día, vendedor y condiciones, tal como estaban en el momento de emitir.
     */
    private function paraGuardar(array $contexto): array
    {
        unset($contexto['tipo'], $contexto['logo']);

        return $contexto;
    }
}
