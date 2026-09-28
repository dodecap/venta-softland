<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Usuario de la app. Vive en `ventas.usuario`, dentro de la base Softland.
 *
 * Una sola forma de autenticarse: `softland_user` contra `softland.wisusuarios`
 * (cifrado propio de Softland). Aquí no se guarda ninguna contraseña, y esa es
 * la razón de que la tabla no tenga columna para ella: quien entra tiene que
 * poder firmar lo que escribe en el ERP.
 *
 * `ven_cod` enlaza con el vendedor de Softland (`softland.cwtvend`): es lo que
 * queda estampado en la cotización y en la nota de venta.
 */
class Usuario extends Model
{
    public const ROLES = ['vendedor', 'supervisor', 'facturacion', 'admin'];

    protected $connection = 'softland';

    protected $table = 'ventas.usuario';

    protected $guarded = ['id'];

    protected $casts = [
        'habilitado' => 'boolean',
        'activo' => 'boolean',
        'tope_descuento_pct' => 'decimal:2',
        'tope_monto_nv' => 'decimal:2',
        // El driver de SQL Server entrega los enteros como string; sin el cast
        // ninguna comparación con un id numérico calza.
        'jefe_id' => 'integer',
    ];

    public function esRol(string ...$roles): bool
    {
        return in_array($this->rol, $roles, true);
    }

    public function jefe()
    {
        return $this->belongsTo(self::class, 'jefe_id');
    }

    /**
     * ¿Es jefe directo o superior (recursivo) del subordinado dado?
     * Sube por la cadena de jefe_id, con tope de profundidad y control de ciclos.
     */
    public function esSuperiorDe(int $subordinadoId, int $maxProf = 20): bool
    {
        $actual = static::on($this->getConnectionName())->find($subordinadoId);
        $vistos = [];
        while ($actual && $actual->jefe_id && $maxProf-- > 0) {
            $jefeId = (int) $actual->jefe_id;
            if ($jefeId === (int) $this->id) {
                return true;
            }
            if (in_array($jefeId, $vistos, true)) {
                break; // organigrama con ciclo
            }
            $vistos[] = $jefeId;
            $actual = static::on($this->getConnectionName())->find($jefeId);
        }

        return false;
    }

    /** IDs de todos los subalternos (recursivo hacia abajo por jefe_id). */
    public function subordinadosIds(int $maxNiveles = 20): array
    {
        $todos = static::on($this->getConnectionName())->get(['id', 'jefe_id']);
        $hijosDe = [];
        foreach ($todos as $x) {
            $hijosDe[(int) ($x->jefe_id ?? 0)][] = (int) $x->id;
        }

        $result = [];
        $cola = $hijosDe[(int) $this->id] ?? [];
        while ($cola && $maxNiveles-- > 0) {
            $siguiente = [];
            foreach ($cola as $id) {
                if ($id === (int) $this->id || in_array($id, $result, true)) {
                    continue;
                }
                $result[] = $id;
                foreach ($hijosDe[$id] ?? [] as $h) {
                    $siguiente[] = $h;
                }
            }
            $cola = $siguiente;
        }

        return array_values(array_unique($result));
    }

    /**
     * Códigos de vendedor de Softland cuyos documentos puede ver este usuario.
     *
     * `null` significa «todos», y es distinto de lista vacía: administración y
     * facturación ven la empresa entera, mientras que un vendedor al que nadie
     * le asignó `ven_cod` no ve nada — no hay documento que sea suyo.
     */
    public function vendedoresVisibles(): ?array
    {
        if ($this->esRol('admin', 'facturacion')) {
            return null;
        }

        $codigos = array_filter([$this->ven_cod]);

        if ($this->esRol('supervisor')) {
            $deSuGente = static::on($this->getConnectionName())
                ->whereIn('id', $this->subordinadosIds())
                ->pluck('ven_cod')
                ->all();
            $codigos = array_merge($codigos, array_filter($deSuGente));
        }

        return array_values(array_unique($codigos));
    }

    /** Lo que la app necesita saber del usuario conectado. */
    public function payload(): array
    {
        return [
            'id' => (int) $this->id,
            'nombre' => $this->nombre,
            'email' => $this->email,
            'rut' => $this->rut,
            'rol' => $this->rol,
            'ven_cod' => $this->ven_cod,
            'cod_bode' => $this->cod_bode,
            'cod_lista' => $this->cod_lista,
            'cod_cc' => $this->cod_cc,
            'jefe_id' => $this->jefe_id,
            'tope_descuento_pct' => (float) $this->tope_descuento_pct,
            'tope_monto_nv' => (float) $this->tope_monto_nv,
            'es_admin' => $this->esRol('admin'),
            // Para que el teléfono sepa a quién mostrarle el switch de
            // aprobar una nota de venta que quedó pendiente sin pasar por el
            // tope (sin fila en `ventas.aprobacion`, así que sin `jefe_id`
            // asignado que lo decida solo). Ojo: no lleva el propio `ven_cod`
            // del supervisor — para eso está `vendedoresVisibles()` — porque
            // aquí la pregunta es «¿puede aprobarle a otro?», no «¿puede ver
            // lo suyo?».
            'subordinados_ven_cod' => $this->esRol('supervisor')
                ? array_values(array_unique(array_filter(static::on($this->getConnectionName())
                    ->whereIn('id', $this->subordinadosIds())
                    ->pluck('ven_cod')
                    ->all())))
                : [],
        ];
    }
}
