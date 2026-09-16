{{--
    El cierre: el timbre a la izquierda, los totales a la derecha.

    El timbre es lo que acredita el documento. El texto de una factura lo puede
    escribir cualquiera; lo que la vuelve un documento tributario es ese
    rectángulo, porque lleva dentro el TED firmado con la llave del CAF.

    Si no hay timbre se dice, en vez de dejar el hueco: un documento sin timbre
    impreso es uno que todavía no viajó al SII, y eso hay que verlo en el papel
    y no descubrirlo cuando el cliente lo rechaza.
--}}
<table class="cierre">
    <tr>
        <td style="width: 52%;" class="timbre">
            @if (! empty($timbre))
                <img src="{{ $timbre }}" alt="Timbre electrónico SII">
                <div class="rotulo">Timbre Electrónico SII</div>
                <div class="resolucion">
                    Res.Nº {{ $identidad['sii_resolucion'] }} de {{ $identidad['sii_resolucion_anio'] }}
                    Verifique documento en www.sii.cl
                </div>
            @else
                <div class="rotulo">Sin timbre electrónico</div>
                <div class="resolucion">
                    Este documento todavía no se ha enviado al Servicio de Impuestos Internos.
                </div>
            @endif
        </td>
        <td style="width: 48%; padding-left: 3mm;">
            <table class="totales">
                <tr>
                    <td class="rotulo">Sub Total</td>
                    <td class="valor">{{ $plata(abs((float) ($documento['neto'] ?? 0)) + abs((float) ($documento['exento'] ?? 0))) }}</td>
                </tr>
                <tr>
                    <td class="rotulo">Monto Exento</td>
                    <td class="valor">{{ ((float) ($documento['exento'] ?? 0)) ? $plata(abs($documento['exento'])) : '-' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">Descuento</td>
                    <td class="valor">{{ ((float) ($documento['descuento'] ?? 0)) ? $plata(abs($documento['descuento'])) : '-' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">Neto</td>
                    <td class="valor">{{ $plata(abs((float) ($documento['neto'] ?? 0))) }}</td>
                </tr>
                <tr>
                    <td class="rotulo">IVA ({{ (int) ($iva_pct ?? 19) }}%)</td>
                    <td class="valor">{{ ((float) ($documento['iva'] ?? 0)) ? $plata(abs($documento['iva'])) : '-' }}</td>
                </tr>
                <tr>
                    <td class="rotulo final">Total</td>
                    <td class="valor final">{{ $plata(abs((float) ($documento['total'] ?? 0))) }}</td>
                </tr>
            </table>
            {{-- El monto en letras. Va desde mucho antes de los ordenadores y
                 sigue por lo mismo: es el resguardo contra el dígito cambiado
                 a mano. --}}
            <div class="son">
                <span class="rotulo">Son:</span>
                {{ \App\Services\Documentos\Palabras::monto((float) ($documento['total'] ?? 0), $moneda_palabra ?? 'PESOS') }}
            </div>
        </td>
    </tr>
</table>
