{{--
    Los tres renglones del final: qué se acordó y de qué venta se trata.

    Los rótulos son fijos —«OBSERVACIÓN», «CONDICIÓN DE PAGO», «TIPO DE VENTA»—
    y lo que los llena son **atributos de la nota de venta**, que cada empresa
    define por su cuenta. Cuál va en cada hueco lo dice la configuración; sin
    configurar, el renglón no se dibuja en vez de salir en blanco.

    El tipo de venta va en rojo, como en el original: es lo que mira quien
    recibe la orden para saber bajo qué campaña o convenio entra.
--}}
@if ($observacion_orden)
    <div style="margin-top: 4mm;">
        <div style="font-weight: bold;">OBSERVACIÓN</div>
        <div>{{ mb_strtoupper($observacion_orden, 'UTF-8') }}</div>
    </div>
@endif

@if ($condicion)
    <div style="margin-top: 3mm;">
        <div style="font-weight: bold;">CONDICIÓN DE PAGO - CONTRA FACTURA:</div>
        <div>{{ $condicion }}</div>
    </div>
@endif

@if ($tipo_venta_orden)
    <div style="margin-top: 3mm;">
        <div style="font-weight: bold;">TIPO DE VENTA</div>
        <div style="color: #c00000; font-weight: bold;">
            {{ mb_strtoupper($tipo_venta_orden, 'UTF-8') }}
        </div>
    </div>
@endif
