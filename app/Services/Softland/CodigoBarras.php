<?php

namespace App\Services\Softland;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Enseñarle a la app el código de barras de un producto.
 *
 * ## Por qué hace falta
 *
 * Porque el maestro viene casi vacío: en INNOVAGES, **134 de 1.229 productos**
 * tienen `CodBarra`, y varios de esos son basura heredada. Un escáner que sólo
 * lee lo que ya está escrito no sirve el primer día, y nadie va a sentarse a
 * cargar mil códigos a mano en el ERP antes de poder usarlo. Se aprenden sobre
 * la marcha: se apunta a la caja, se dice de qué producto es, y a partir de ahí
 * ese código lo reconoce toda la empresa.
 *
 * ## Dónde se guarda, y por qué ahí
 *
 * En `softland.iw_tprod.CodBarra`, que es la columna del ERP. Se consideró una
 * tabla propia en el esquema `ventas` —no tocar el maestro de otro— y se
 * descartó a propósito: un código que sólo conoce esta app es un código que el
 * Softland de escritorio no encuentra, y acabarían dos verdades sobre lo mismo.
 * Escribiendo en `iw_tprod` el código sirve en los dos sitios y se puede
 * corregir desde cualquiera de los dos.
 *
 * ## Las cinco reglas
 *
 * Escribir en el maestro de productos de un ERP desde el teléfono de un
 * vendedor sólo es aceptable si no puede romper nada de lo que ya funciona:
 *
 *  1. **Sólo si está vacío.** Un producto que ya tiene código lo escanea hoy
 *     el escritorio; pisarlo rompe algo que funciona. Cambiarlo es cosa del
 *     ERP, no de esto.
 *  2. **Sólo si no lo tiene otro producto.** `iw_tprod.CodBarra` no lleva
 *     índice único, así que la base acepta el duplicado sin rechistar y el
 *     problema sale el día que alguien escanee y salgan dos.
 *  3. **Veinte caracteres.** Es lo que mide la columna. Un QR largo no cabe, y
 *     eso se dice — recortarlo en silencio guarda un código que no es el que
 *     está impreso.
 *  4. **Sólo esa columna.** Ni `Proceso`, ni `Usuario`, ni `FechaUlMod`: nada
 *     que el ERP mire para decidir otra cosa.
 *  5. **Quién y cuándo, en `ventas`.** `iw_tprod` no guarda autor. La huella
 *     de la app se lleva en el esquema de la app, que es la regla de siempre.
 *
 * Está comprobado que el `UPDATE` es inocuo para los disparadores del ERP:
 * `IW_TProd_UTRIG` sólo escribe en `LogIW_TProd` cuando `Proceso` vale
 * «Correccion Monetaria», y los dieciocho que propagan cambios están todos
 * guardados con `IF UPDATE(CodProd)`.
 */
class CodigoBarras
{
    /** Lo que mide `iw_tprod.CodBarra`: `varchar(20)`. */
    public const LARGO_MAXIMO = 20;

    private const CONN = 'softland';

    /**
     * Guarda el código en el producto. Devuelve el producto con su código.
     *
     * @throws RuntimeException  con un mensaje que se le puede enseñar a quien escanea
     */
    public function asignar(string $producto, string $codigo, ?string $usuario = null): array
    {
        $producto = trim($producto);
        $codigo = trim($codigo);

        if ($codigo === '') {
            throw new RuntimeException('El código de barras viene vacío.');
        }

        // Regla 3. Antes que nada, porque es la única que no depende de la base.
        if (mb_strlen($codigo) > self::LARGO_MAXIMO) {
            throw new RuntimeException(sprintf(
                'Ese código tiene %d caracteres y en Softland caben %d. No se puede guardar entero, '
                .'y guardarlo cortado sería guardar otro código.',
                mb_strlen($codigo),
                self::LARGO_MAXIMO
            ));
        }

        return DB::connection(self::CONN)->transaction(function () use ($producto, $codigo, $usuario) {
            $ficha = DB::connection(self::CONN)->table('softland.iw_tprod')
                ->where('CodProd', $producto)
                ->lockForUpdate()
                ->first(['CodProd', 'DesProd', 'CodBarra']);

            if (! $ficha) {
                throw new RuntimeException("El producto $producto no está en Softland.");
            }

            // Regla 1. Ojo con el caso de volver a guardar el mismo: no es un
            // choque, es un reenvío, y contestar que no se puede sería mentir.
            $actual = trim((string) $ficha->CodBarra);
            if ($actual !== '' && $actual !== $codigo) {
                throw new RuntimeException(
                    "Ese producto ya tiene el código $actual. Cambiarlo se hace desde Softland."
                );
            }

            // Regla 2. Se pregunta siempre, también al reenviar: entre el
            // primer intento y éste puede habérselo llevado otro.
            $otro = DB::connection(self::CONN)->table('softland.iw_tprod')
                ->where('CodBarra', $codigo)
                ->where('CodProd', '<>', $producto)
                ->value('CodProd');

            if ($otro) {
                throw new RuntimeException(
                    "Ese código ya es del producto $otro. Un código de barras no puede ser de dos."
                );
            }

            if ($actual === '') {
                // Regla 4: una sola columna.
                DB::connection(self::CONN)->table('softland.iw_tprod')
                    ->where('CodProd', $producto)
                    ->update(['CodBarra' => $codigo]);

                // Regla 5: quién y cuándo, en el esquema de la app.
                DB::connection(self::CONN)->table('ventas.codigo_barras_app')->insert([
                    'producto' => $producto,
                    'barra' => $codigo,
                    'usuario' => $usuario,
                    'creado_en' => now(),
                ]);
            }

            return [
                'codigo' => trim((string) $ficha->CodProd),
                'nombre' => trim((string) $ficha->DesProd),
                'barra' => $codigo,
            ];
        });
    }
}
