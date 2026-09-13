{{--
    El PDF que se le manda al cliente.

    Dompdf no entiende flex ni grid: es un motor de 2005 con soporte de CSS 2.1.
    Todo va con tablas y con estilos escritos en la etiqueta, igual que el
    correo. Lo que aquí parezca anticuado está así porque es lo único que dibuja.
--}}
@php
    $simbolo = $moneda_simbolo ?? '$';
    $plata = fn ($v) => $simbolo.' '.number_format((float) $v, 0, ',', '.');
    $cant = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $iva = ($documento['total'] ?? 0) - ($documento['neto'] ?? 0) - ($documento['exento'] ?? 0);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 20mm 16mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #15112c; }
        .cinta { background: #1d1060; color: #fff; padding: 12px 14px; }
        .cinta h1 { margin: 0; font-size: 16pt; }
        .cinta p { margin: 3px 0 0; font-size: 9pt; }
        .barra-cian { height: 4px; background: #26bdef; margin-bottom: 16px; }
        h2 { font-size: 10pt; color: #1d1060; margin: 18px 0 6px; text-transform: uppercase; letter-spacing: .08em; }
        table { width: 100%; border-collapse: collapse; }
        .datos td { padding: 2px 0; font-size: 9pt; }
        .datos .rot { color: #6b6880; width: 34%; }
        .detalle th { background: #f2f1f9; color: #1d1060; font-size: 8.5pt; text-transform: uppercase;
                      letter-spacing: .05em; padding: 6px 7px; border-bottom: 2px solid #26bdef; }
        .detalle td { padding: 6px 7px; border-bottom: 1px solid #e6e4ef; vertical-align: top; }
        .der { text-align: right; }
        .cod { color: #6b6880; font-size: 8pt; }
        .totales { width: 48%; margin-left: 52%; margin-top: 12px; }
        .totales td { padding: 3px 0; font-size: 9.5pt; }
        .totales .rot { color: #6b6880; }
        .totales .final td { border-top: 2px solid #1d1060; padding-top: 6px; font-weight: bold; font-size: 11pt; }
        .nota { margin-top: 20px; padding: 9px 11px; background: #f2f1f9; font-size: 9pt; }
        .pie { margin-top: 26px; padding-top: 8px; border-top: 1px solid #dedce9;
               color: #6b6880; font-size: 8pt; }
    </style>
</head>
<body>
    <div class="cinta">
        <h1>{{ $titulo }}</h1>
        <p>
            {{ $empresa['nombre'] ?? config('app.name') }}@if (! empty($rut_emisor)) · RUT {{ $rut_emisor }}@endif
            @if (! empty($empresa['direccion']))<br>{{ $empresa['direccion'] }}@endif
            @if (! empty($empresa['fono'])) · Fono {{ $empresa['fono'] }}@endif
        </p>
    </div>
    <div class="barra-cian"></div>

    <table>
        <tr>
            <td style="width:50%; vertical-align:top;">
                <h2 style="margin-top:0;">Cliente</h2>
                <table class="datos">
                    <tr><td class="rot">Nombre</td><td>{{ $cliente['nombre'] ?? '' }}</td></tr>
                    @if (! empty($cliente['rut']))
                        <tr><td class="rot">RUT</td><td>{{ $cliente['rut'] }}</td></tr>
                    @endif
                    @if (! empty($cliente['direccion']))
                        <tr><td class="rot">Dirección</td><td>{{ $cliente['direccion'] }}</td></tr>
                    @endif
                    @if (! empty($documento['contacto']))
                        <tr><td class="rot">Contacto</td><td>{{ $documento['contacto'] }}</td></tr>
                    @endif
                </table>
            </td>
            <td style="width:50%; vertical-align:top;">
                <h2 style="margin-top:0;">Documento</h2>
                <table class="datos">
                    <tr><td class="rot">Fecha</td><td>{{ substr((string) ($documento['fecha'] ?? ''), 0, 10) }}</td></tr>
                    @if (! empty($documento['fecha_entrega']))
                        <tr><td class="rot">Entrega</td><td>{{ substr((string) $documento['fecha_entrega'], 0, 10) }}</td></tr>
                    @endif
                    @if (! empty($vendedor))
                        <tr><td class="rot">Vendedor</td><td>{{ $vendedor }}</td></tr>
                    @endif
                    @if (! empty($condicion))
                        <tr><td class="rot">Condición</td><td>{{ $condicion }}</td></tr>
                    @endif
                    @if (! empty($documento['oc']) && $documento['oc'] !== '0')
                        <tr><td class="rot">Orden de compra</td><td>{{ $documento['oc'] }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <h2>Detalle</h2>
    <table class="detalle">
        <thead>
            <tr>
                <th align="left">Producto</th>
                <th class="der">Cant.</th>
                <th class="der">Unitario</th>
                <th class="der">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lineas as $l)
                <tr>
                    <td>
                        {{ $l['detalle'] ?: ($nombres[$l['producto']] ?? $l['producto']) }}<br>
                        <span class="cod">{{ $l['producto'] }}</span>
                        {{-- Sin esto la resta no cuadra a ojo: el cliente
                             multiplica cantidad por unitario y le da otra cosa. --}}
                        @if ((float) ($l['descuento'] ?? 0) > 0)
                            <span class="cod"> · descuento {{ $plata($l['descuento']) }}</span>
                        @endif
                    </td>
                    <td class="der">{{ $cant($l['cantidad']) }}</td>
                    <td class="der">{{ $plata(((float) $l['precio']) * ((float) ($l['equiv'] ?: 1))) }}</td>
                    <td class="der">{{ $plata($l['total']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        @if ((float) ($documento['descuento'] ?? 0) > 0)
            <tr><td class="rot">Descuento</td><td class="der">− {{ $plata($documento['descuento']) }}</td></tr>
        @endif
        @if ((float) ($documento['exento'] ?? 0) > 0)
            <tr><td class="rot">Exento</td><td class="der">{{ $plata($documento['exento']) }}</td></tr>
        @endif
        <tr><td class="rot">Neto afecto</td><td class="der">{{ $plata($documento['neto'] ?? 0) }}</td></tr>
        <tr><td class="rot">IVA</td><td class="der">{{ $plata($iva) }}</td></tr>
        <tr class="final"><td>Total</td><td class="der">{{ $plata($documento['total'] ?? 0) }}</td></tr>
    </table>

    @if (! empty($documento['observacion']))
        <div class="nota">{{ $documento['observacion'] }}</div>
    @endif

    <div class="pie">
        Los precios están expresados en {{ $moneda_nombre ?? 'pesos chilenos' }}.
        Documento generado el {{ now()->format('d-m-Y H:i') }}.
    </div>
</body>
</html>
