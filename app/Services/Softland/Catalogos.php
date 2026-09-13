<?php

namespace App\Services\Softland;

use Illuminate\Support\Facades\DB;

/**
 * Lectura de los maestros nativos de Softland (esquema `softland`).
 *
 * Ojo: las tablas de Softland **no** están en `dbo`, están en el esquema
 * `softland`. Hay que nombrarlas siempre completas o el SELECT falla con
 * "Invalid object name".
 *
 * Esta clase es de SOLO LECTURA: los maestros los mantiene Softland, la app
 * nunca los escribe.
 */
class Catalogos
{
    protected function conn()
    {
        return DB::connection('softland');
    }

    /** Vendedores (softland.cwtvend) — lo que se estampa en cotización y NV. */
    public function vendedores(): array
    {
        return $this->conn()
            ->table('softland.cwtvend')
            ->orderBy('VenDes')
            ->get(['VenCod as codigo', 'VenDes as nombre', 'EMail as email'])
            ->map(fn ($v) => [
                'codigo' => trim((string) $v->codigo),
                'nombre' => trim((string) $v->nombre),
                'email' => trim((string) $v->email),
            ])->all();
    }

    /** Usuarios con licencia Softland (softland.wisusuarios). Sin contraseñas. */
    public function usuariosSoftland(): array
    {
        return $this->conn()
            ->table('softland.wisusuarios')
            ->orderBy('Usuario')
            ->get(['Usuario as usuario', 'Nombre as nombre', 'Rut as rut', 'eMail as email', 'Bloqueado as bloqueado'])
            ->map(fn ($u) => [
                'usuario' => trim((string) $u->usuario),
                'nombre' => trim((string) $u->nombre),
                'rut' => trim((string) $u->rut),
                'email' => trim((string) $u->email),
                'bloqueado' => in_array(strtoupper(trim((string) $u->bloqueado)), ['S', '1', 'T'], true),
            ])->all();
    }

    /** Bodegas (softland.iw_tbode). */
    public function bodegas(): array
    {
        return $this->conn()
            ->table('softland.iw_tbode')
            ->orderBy('DesBode')
            ->get(['CodBode as codigo', 'DesBode as nombre'])
            ->map(fn ($b) => ['codigo' => trim((string) $b->codigo), 'nombre' => trim((string) $b->nombre)])
            ->all();
    }

    /** Listas de precio (softland.iw_tlispre). */
    public function listasPrecio(): array
    {
        return $this->conn()
            ->table('softland.iw_tlispre')
            ->orderBy('DesLista')
            ->get(['CodLista as codigo', 'DesLista as nombre', 'TipoLista as tipo'])
            ->map(fn ($l) => [
                'codigo' => trim((string) $l->codigo),
                'nombre' => trim((string) $l->nombre),
                'tipo' => trim((string) $l->tipo),
            ])->all();
    }

    /** Centros de costo (softland.cwtccos). */
    public function centrosCosto(): array
    {
        return $this->conn()
            ->table('softland.cwtccos')
            ->orderBy('CcDes')
            ->get(['CcCod as codigo', 'CcDes as nombre'])
            ->map(fn ($c) => ['codigo' => trim((string) $c->codigo), 'nombre' => trim((string) $c->nombre)])
            ->all();
    }

    /** Condiciones de venta / pago (softland.cwtconv). */
    public function condicionesVenta(): array
    {
        return $this->conn()
            ->table('softland.cwtconv')
            ->orderBy('CveDes')
            ->get(['CveCod as codigo', 'CveDes as nombre', 'CveDias as dias'])
            ->map(fn ($c) => [
                'codigo' => trim((string) $c->codigo),
                'nombre' => trim((string) $c->nombre),
                'dias' => (int) $c->dias,
            ])->all();
    }

    /**
     * Correo de contacto de un cliente (softland.cwtauxi).
     * `eMailDTE` es el que Softland usa para mandar el documento tributario;
     * `EMail` es el comercial. Se prefiere el comercial para cotizaciones.
     */
    public function emailCliente(?string $codAux): ?string
    {
        if (! $codAux) {
            return null;
        }

        $row = $this->conn()
            ->table('softland.cwtauxi')
            ->where('CodAux', $codAux)
            ->first(['EMail', 'eMailDTE']);

        foreach ([$row->EMail ?? null, $row->eMailDTE ?? null] as $e) {
            $e = trim((string) $e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                return $e;
            }
        }

        return null;
    }

    /** Datos de la empresa emisora, leídos del CAF vigente. */
    public function rutEmisor(): ?string
    {
        $rut = $this->conn()->table('softland.dte_siicaf')->orderByDesc('Fecha')->value('RUT');

        return $rut ? trim((string) $rut) : null;
    }
}
