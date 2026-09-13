{{--
    Cuerpo del correo de una cotización o de una nota de venta.

    Sale con estilos escritos en cada etiqueta y con tablas: es lo único que
    dibujan igual Gmail, Outlook y el correo del teléfono. Nada de hojas de
    estilo ni de flex, que Outlook las ignora y deja el documento apilado.

    El envoltorio (cabecera, pie, firma) lo pone el Notificador: aquí va solo
    lo que cambia de un documento a otro.
--}}
@php
    $simbolo = $moneda_simbolo ?? '$';
    $plata = fn ($v) => $simbolo.' '.number_format((float) $v, 0, ',', '.');
@endphp

<h2 style="margin:0 0 4px;font:600 19px/1.25 Arial,sans-serif;color:#1d1060;">{{ $titulo }}</h2>
<p style="margin:0 0 18px;font:14px/1.5 Arial,sans-serif;color:#5c5872;">
    {{ $cliente['nombre'] ?? '' }}
    @if (! empty($cliente['rut'])) · {{ $cliente['rut'] }} @endif
</p>

<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="border-collapse:collapse;font:13px/1.45 Arial,sans-serif;color:#15112c;">
    <tr>
        <td style="padding:0 0 14px;">
            <table role="presentation" cellpadding="0" cellspacing="0"
                   style="border-collapse:collapse;font:13px/1.6 Arial,sans-serif;">
                <tr>
                    <td style="padding-right:18px;color:#6b6880;">Fecha</td>
                    <td style="font-weight:bold;">{{ $documento['fecha'] ?? '' }}</td>
                </tr>
                @if (! empty($documento['fecha_entrega']))
                    <tr>
                        <td style="padding-right:18px;color:#6b6880;">Entrega</td>
                        <td style="font-weight:bold;">{{ $documento['fecha_entrega'] }}</td>
                    </tr>
                @endif
                @if (! empty($documento['contacto']))
                    <tr>
                        <td style="padding-right:18px;color:#6b6880;">Contacto</td>
                        <td style="font-weight:bold;">{{ $documento['contacto'] }}</td>
                    </tr>
                @endif
                @if (! empty($documento['oc']) && $documento['oc'] !== '0')
                    <tr>
                        <td style="padding-right:18px;color:#6b6880;">Orden de compra</td>
                        <td style="font-weight:bold;">{{ $documento['oc'] }}</td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="border-collapse:collapse;font:13px/1.4 Arial,sans-serif;color:#15112c;">
    <thead>
        <tr>
            <th align="left" style="padding:7px 8px;border-bottom:2px solid #26bdef;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#1d1060;">Producto</th>
            <th align="right" style="padding:7px 8px;border-bottom:2px solid #26bdef;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#1d1060;">Cant.</th>
            <th align="right" style="padding:7px 8px;border-bottom:2px solid #26bdef;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#1d1060;">Unitario</th>
            <th align="right" style="padding:7px 8px;border-bottom:2px solid #26bdef;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#1d1060;">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lineas as $l)
            <tr>
                <td style="padding:7px 8px;border-bottom:1px solid #e6e4ef;">
                    {{ $l['detalle'] ?: ($nombres[$l['producto']] ?? $l['producto']) }}
                    <br><span style="color:#6b6880;font-size:11px;">
                        {{ $l['producto'] }}@if ((float) ($l['descuento'] ?? 0) > 0) · descuento {{ $plata($l['descuento']) }}@endif
                    </span>
                </td>
                <td align="right" style="padding:7px 8px;border-bottom:1px solid #e6e4ef;white-space:nowrap;">
                    {{ rtrim(rtrim(number_format((float) $l['cantidad'], 2, ',', '.'), '0'), ',') }}
                </td>
                <td align="right" style="padding:7px 8px;border-bottom:1px solid #e6e4ef;white-space:nowrap;">
                    {{ $plata(((float) $l['precio']) * ((float) ($l['equiv'] ?: 1))) }}
                </td>
                <td align="right" style="padding:7px 8px;border-bottom:1px solid #e6e4ef;white-space:nowrap;">
                    {{ $plata($l['total']) }}
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

<table role="presentation" cellpadding="0" cellspacing="0" align="right"
       style="border-collapse:collapse;margin-top:14px;font:13px/1.7 Arial,sans-serif;color:#15112c;">
    @if ((float) ($documento['descuento'] ?? 0) > 0)
        <tr>
            <td style="padding-right:22px;color:#6b6880;">Descuento</td>
            <td align="right" style="white-space:nowrap;">− {{ $plata($documento['descuento']) }}</td>
        </tr>
    @endif
    @if ((float) ($documento['exento'] ?? 0) > 0)
        <tr>
            <td style="padding-right:22px;color:#6b6880;">Exento</td>
            <td align="right" style="white-space:nowrap;">{{ $plata($documento['exento']) }}</td>
        </tr>
    @endif
    <tr>
        <td style="padding-right:22px;color:#6b6880;">Neto afecto</td>
        <td align="right" style="white-space:nowrap;">{{ $plata($documento['neto'] ?? 0) }}</td>
    </tr>
    <tr>
        <td style="padding-right:22px;color:#6b6880;">IVA</td>
        <td align="right" style="white-space:nowrap;">
            {{ $plata(($documento['total'] ?? 0) - ($documento['neto'] ?? 0) - ($documento['exento'] ?? 0)) }}
        </td>
    </tr>
    <tr>
        <td style="padding:6px 22px 0 0;border-top:2px solid #1d1060;font-weight:bold;">Total</td>
        <td align="right" style="padding-top:6px;border-top:2px solid #1d1060;font-weight:bold;white-space:nowrap;">
            {{ $plata($documento['total'] ?? 0) }}
        </td>
    </tr>
</table>

<div style="clear:both;"></div>

@if (! empty($documento['observacion']))
    <p style="margin:26px 0 0;padding:11px 13px;background:#f2f1f9;font:13px/1.5 Arial,sans-serif;color:#15112c;">
        {{ $documento['observacion'] }}
    </p>
@endif
