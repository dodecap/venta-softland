<?php

namespace App\Services\Documentos;

use Illuminate\Support\Facades\DB;

/**
 * Lo que hace falta para pedirle a un proveedor lo que un cliente compró.
 *
 * ## Qué es la orden de compra aquí
 *
 * En el ciclo de distribuidor, la nota de venta tiene dos caras. Mirada hacia
 * el cliente es una nota de venta; mirada hacia el proveedor es una orden de
 * compra: el mismo número, las mismas líneas y el mismo total, dirigidos a
 * quien tiene que despachar. INNOVAGES le vende a su cliente y se lo pide a
 * Softland Santiago.
 *
 * ## Por qué esto es configuración y no código
 *
 * Porque nada de ello se puede deducir del documento:
 *
 *  - **quién es el proveedor**. Se guarda su código de `cwtauxi` y de ahí salen
 *    su razón social, su RUT, su giro y su contacto. Un código, no diez campos
 *    copiados que envejecen por separado;
 *  - **qué atributo va en cada hueco del papel**. Los atributos los define cada
 *    empresa —INNOVAGES tiene cuatro y NETDOMAIN uno— y el papel tiene dos
 *    huecos con nombre propio, «OBSERVACIÓN» y «TIPO DE VENTA», que no se
 *    llaman como el atributo que los llena. En INNOVAGES el primero es «TIPO DE
 *    CONTRATO» y el segundo «Tipo de Venta»; en otra empresa serán otros, o
 *    ninguno.
 *
 * Sin configurar, la orden de compra se dibuja igual: sin proveedor en la caja
 * de «Señores» y sin esas dos líneas. Lo que no hace es inventárselos.
 */
class ReglasOrdenCompra
{
    public const CLAVE = 'orden_compra';

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

    /** El código del proveedor en `cwtauxi`, o null si nadie lo configuró. */
    public function proveedorCodigo(): ?string
    {
        $codigo = trim((string) ($this->valores()['proveedor'] ?? ''));

        return $codigo === '' ? null : $codigo;
    }

    /**
     * La ficha del proveedor, leída de Softland.
     *
     * Se lee y no se copia: si le cambian la dirección en el ERP, el próximo
     * papel sale con la nueva. Copiarla en la configuración sería tener dos
     * verdades esperando a diferenciarse.
     */
    public function proveedor(): ?array
    {
        $codigo = $this->proveedorCodigo();

        if (! $codigo) {
            return null;
        }

        $conn = DB::connection('softland');
        $p = $conn->table('softland.cwtauxi')->where('CodAux', $codigo)->first();

        if (! $p) {
            return null;
        }

        $t = fn ($v) => trim((string) ($v ?? ''));

        return [
            'codigo' => $codigo,
            'nombre' => $t($p->NomAux ?? ''),
            'rut' => $t($p->RutAux ?? ''),
            'direccion' => $t($p->DirAux ?? ''),
            'giro' => $t($conn->table('softland.cwtgiro')->where('GirCod', $t($p->GirAux ?? ''))->value('GirDes')),
            'comuna' => $t($conn->table('softland.cwtcomu')->where('ComCod', $t($p->ComAux ?? ''))->value('ComDes')),
            'ciudad' => $t($conn->table('softland.cwtciud')->where('CiuCod', $t($p->CiuAux ?? ''))->value('CiuDes')),
            'fono' => $t($p->FonAux1 ?? ''),
            'contacto' => $t($this->valores()['contacto'] ?? ''),
            'correo' => $t($this->valores()['correo'] ?? ''),
        ];
    }

    /**
     * Qué atributo de la nota de venta va en cada hueco con nombre del papel.
     *
     * Devuelve el código del atributo, no su nombre: los nombres los cambia
     * cualquiera desde el ERP y el código es lo estable.
     *
     * @return array{observacion: ?int, tipo_venta: ?int, fecha: ?int}
     */
    public function huecos(): array
    {
        $v = $this->valores();
        $n = fn ($k) => ($v[$k] ?? null) !== null && $v[$k] !== '' ? (int) $v[$k] : null;

        return [
            'observacion' => $n('atributo_observacion'),
            'tipo_venta' => $n('atributo_tipo_venta'),
            // Cuál es la fecha de la orden. Si apunta a un atributo de fecha
            // —en INNOVAGES, «Fech. Envio SOFTLAND»— se usa ése; si no, la del
            // documento.
            'fecha' => $n('atributo_fecha'),
        ];
    }

    /** Entre pruebas: la memoria no puede sobrevivir a un cambio. */
    public static function olvidar(): void
    {
        self::$cache = null;
    }
}
