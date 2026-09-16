{{--
    La cabecera de la cotización: los dos logos y el título.

    A la izquierda el de la marca que la empresa representa, a la derecha el
    propio — en la nota de venta van al revés, y no es un descuido: así los tiene
    la empresa desde siempre y así los reconoce el cliente.

    Las dos barras grises no son adorno: enmarcan la cabecera y separan el
    membrete del documento, que es lo que hace que la hoja se lea de un vistazo.
--}}
<table class="logos">
    <tr>
        <td style="width: 50%;">
            @if ($logo_secundario)
                <img class="logo-izq" src="{{ $logo_secundario }}" alt="">
            @endif
        </td>
        <td style="width: 50%; text-align: right;">
            @if ($logo)
                <img class="logo-der" src="{{ $logo }}" alt="{{ $identidad['nombre_comercial'] }}">
            @else
                <div class="rotulo-empresa">{{ $identidad['nombre_comercial'] }}</div>
            @endif
        </td>
    </tr>
</table>

<div class="barra"></div>

<div class="titulo-doc">
    {{ $tipo->titulo() }} N°&nbsp; {{ $documento['numero'] }}
    &nbsp;&nbsp;del&nbsp;&nbsp; {{ $dia($documento['fecha'] ?? null) }}
</div>
