<?php

namespace App\Services\Softland;

use Illuminate\Support\Facades\DB;

/**
 * Lo que el usuario puede hacer según Softland, no según esta app.
 *
 * ## Dos permisos que no son lo mismo
 *
 * La app tiene sus roles —vendedor, supervisor, facturación, administración— y
 * de ahí sale el alcance: qué documentos ve cada uno y a nombre de quién los
 * graba. Eso es **nuestro**, y seguirá siéndolo.
 *
 * Esto es otra cosa: Softland ya decide, por perfil y por usuario, quién puede
 * hacer qué dentro del ERP. Donde el permiso es **sobre el documento**, manda
 * él — es la misma regla que ya se sigue con `nwparam.CheckApruebaNv`. Subirle
 * el rol en la app a alguien no puede darle en Softland algo que el
 * administrador del ERP le negó, ni al revés.
 *
 * ## Cómo están guardados
 *
 * Son **concesiones**, no prohibiciones: la fila existe = puede. Se comprobó
 * contra INNOVAGES — el perfil `NW/001` tiene 205 filas y `NW/vendedor` 164, y
 * lo que le falta al segundo es justo lo que no puede hacer.
 *
 * Y se conceden en **dos sitios que se suman**: por perfil (`wisrestperfil`,
 * atado al usuario por `wisperfilusuario`) y por usuario (`wisrestusuario`).
 * Un usuario puede tener varios perfiles del mismo sistema — en INNOVAGES
 * `jpalomin` tiene `IW/001` **y** `IW/vend` a la vez—, así que basta con que
 * uno se lo conceda. Preguntar sólo por un perfil daría que no a alguien que sí
 * puede.
 *
 * ## El catálogo de permisos
 *
 * Los nombres no se inventan: están en `wisrestricciones`, con su descripción
 * en castellano, y son los mismos que ve el administrador en la pantalla de
 * perfiles del Softland de escritorio. Por eso las constantes de aquí llevan
 * sistema, formulario y control tal cual, y los mensajes de error los nombran
 * igual: quien tenga que ir a marcarlo sabrá dónde.
 *
 * Se declara el permiso que se usa, y no un catálogo entero: son 4.233 controles
 * y copiarlos aquí sería una segunda lista que se queda atrás.
 */
class Permisos
{
    private const CONN = 'softland';

    /**
     * Facturar contra la nota de venta de otro cliente.
     *
     * Es el ciclo de distribuidor: se cotiza y se vende al cliente final, y la
     * factura —la comisión— va al mandante. Softland lo tiene previsto y lo
     * cierra con este permiso; en INNOVAGES lo trae el perfil `IW/001` y no el
     * `IW/vend`, que es exactamente «los vendedores hacen el flujo normal».
     */
    public const FACTURA_OTRO_CLIENTE = ['IW', 'Iw_FacLin', 'NVOtroAuxiliar'];

    /**
     * Lo ya preguntado en esta petición.
     *
     * Una misma comprobación se repite dentro de una emisión —la propuesta, la
     * escritura, el aviso de la pantalla— y son dos consultas cada vez. El
     * proceso muere con la petición, así que no hay nada que invalidar.
     *
     * @var array<string, bool>
     */
    private static array $cache = [];

    public function __construct(private readonly ?string $base = null) {}

    /**
     * ¿Este usuario de Softland tiene concedido este control?
     *
     * @param  string  $usuario  el de `wisusuarios`, que es el `softland_user`
     *                           de nuestra tabla de usuarios
     * @param  array{0: string, 1: string, 2: string}  $permiso  sistema, formulario y control
     */
    public function puede(?string $usuario, array $permiso): bool
    {
        $usuario = trim((string) $usuario);
        [$sistema, $formulario, $control] = $permiso;

        // Sin usuario de Softland no hay a quién preguntarle. Se responde que
        // no: el permiso se concede, no se presume.
        if ($usuario === '') {
            return false;
        }

        $clave = "{$usuario}|{$sistema}|{$formulario}|{$control}";

        return self::$cache[$clave] ??= $this->consultar($usuario, $sistema, $formulario, $control);
    }

    private function consultar(string $usuario, string $sistema, string $formulario, string $control): bool
    {
        $porPerfil = DB::connection(self::CONN)
            ->table($this->califica('wisperfilusuario').' as pu')
            ->join($this->califica('wisrestperfil').' as p', function ($j) {
                $j->on('p.Sistema', '=', 'pu.Sistema')->on('p.Perfil', '=', 'pu.Perfil');
            })
            ->where('pu.Usuario', $usuario)
            ->where('p.Sistema', $sistema)
            ->where('p.Formulario', $formulario)
            ->where('p.Control', $control)
            ->exists();

        if ($porPerfil) {
            return true;
        }

        return DB::connection(self::CONN)->table($this->califica('wisrestusuario'))
            ->where('Usuario', $usuario)
            ->where('Sistema', $sistema)
            ->where('Formulario', $formulario)
            ->where('Control', $control)
            ->exists();
    }

    private function califica(string $objeto): string
    {
        return $this->base ? "{$this->base}.softland.{$objeto}" : "softland.{$objeto}";
    }
}
