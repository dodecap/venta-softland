{{--
    A quién va y bajo qué condiciones. Dos columnas: la identidad del cliente a
    la izquierda, lo comercial a la derecha.

    Regla del bloque: **no se imprime una etiqueta sin dato**. Una ficha llena de
    rótulos vacíos se lee como un formulario a medio llenar, no como un
    documento. Por eso cada fila va dentro de su propio `@if`.
--}}
@php
    // En Chile la comuna y la ciudad coinciden más veces de las que no
    // («Concepción, Concepción» no informa de nada): se deduplica.
    $ubicacion = array_unique(array_filter([
        trim((string) ($cliente['direccion'] ?? '')),
        trim((string) ($comuna_cliente ?? '')),
        trim((string) ($ciudad_cliente ?? '')),
    ]));

    $fonoContacto = trim((string) ($contacto['fono'] ?? ''));
    $emailContacto = trim((string) ($contacto['email'] ?? ''));
@endphp

<table class="no-partir" style="margin-bottom: 10pt;">
    <tr>
        <td style="width:52%; padding-right:14pt; vertical-align:top;">
            <h2>Cliente</h2>
            <table class="ficha">
                <tr><td class="rot">Señores</td><td><strong>{{ $cliente['nombre'] ?? '' }}</strong></td></tr>
                @if (! empty($cliente['rut']))
                    <tr><td class="rot">RUT</td><td>{{ $cliente['rut'] }}</td></tr>
                @endif
                @if (! empty($giro_cliente))
                    <tr><td class="rot">Giro</td><td>{{ $giro_cliente }}</td></tr>
                @endif
                @if ($ubicacion)
                    <tr><td class="rot">Dirección</td><td>{{ implode(', ', $ubicacion) }}</td></tr>
                @endif
                @if (! empty($documento['contacto']))
                    <tr>
                        <td class="rot">Atención</td>
                        <td>
                            {{ $documento['contacto'] }}
                            @if ($fonoContacto || $emailContacto)
                                @foreach (array_filter([$fonoContacto, $emailContacto]) as $via)
                                    <br><span class="tenue">{{ $via }}</span>
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @endif
            </table>
        </td>
        <td style="width:48%; vertical-align:top;">
            <h2>Condiciones</h2>
            <table class="ficha">
                @if (! empty($estado_nombre))
                    <tr><td class="rot">Estado</td><td><strong>{{ $estado_nombre }}</strong></td></tr>
                @endif
                @if (! empty($vendedor['nombre']))
                    <tr><td class="rot">Atendido por</td><td>{{ $vendedor['nombre'] }}</td></tr>
                @endif
                @if (! empty($condicion))
                    <tr><td class="rot">Forma de pago</td><td>{{ $condicion }}</td></tr>
                @endif
                <tr><td class="rot">Moneda</td><td>{{ $moneda_nombre }}</td></tr>
                @if (! empty($vigencia))
                    <tr><td class="rot">Válida hasta</td><td>{{ $vigencia }}</td></tr>
                @endif
                @if (! empty($documento['cotizacion']))
                    <tr><td class="rot">Cotización</td><td>N° {{ $documento['cotizacion'] }}</td></tr>
                @endif
                {{-- `numOC` es NOT NULL con DEFAULT 0 en Softland: un cero ahí
                     significa «sin orden de compra», no una OC número cero. --}}
                @if (! empty($documento['oc']) && $documento['oc'] !== '0')
                    <tr><td class="rot">Orden de compra</td><td>{{ $documento['oc'] }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>
