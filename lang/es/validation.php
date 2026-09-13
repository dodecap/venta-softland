<?php

/*
 * Mensajes de validación en castellano.
 *
 * Laravel ya no trae archivos de idioma, y sin esto el vendedor ve
 * «validation.required» en la pantalla. Solo están las reglas que la API usa de
 * verdad: agregar una regla nueva es agregar su mensaje aquí.
 *
 * El tuteo es a propósito: la app le habla al vendedor, no a un cliente.
 */
return [
    'accepted' => 'Hay que aceptar :attribute.',
    'array' => ':Attribute tiene que ser una lista.',
    'between' => [
        'numeric' => ':Attribute tiene que estar entre :min y :max.',
        'string' => ':Attribute tiene que tener entre :min y :max caracteres.',
    ],
    'boolean' => ':Attribute solo puede ser sí o no.',
    'date' => ':Attribute no es una fecha válida.',
    'different' => ':Attribute y :other tienen que ser distintos.',
    'email' => ':Attribute no es un correo válido.',
    'exists' => ':Attribute no existe.',
    'gt' => [
        'numeric' => ':Attribute tiene que ser mayor que :value.',
    ],
    'in' => ':Attribute no es una opción válida.',
    'integer' => ':Attribute tiene que ser un número entero.',
    'max' => [
        'array' => ':Attribute no puede tener más de :max elementos.',
        'numeric' => ':Attribute no puede ser mayor que :max.',
        'string' => ':Attribute no puede tener más de :max caracteres.',
    ],
    'min' => [
        'array' => ':Attribute tiene que tener al menos :min elementos.',
        'numeric' => ':Attribute tiene que ser al menos :min.',
        'string' => ':Attribute tiene que tener al menos :min caracteres.',
    ],
    'numeric' => ':Attribute tiene que ser un número.',
    'required' => 'Falta :attribute.',
    'required_if' => 'Falta :attribute.',
    'string' => ':Attribute tiene que ser texto.',
    'unique' => ':Attribute ya está ocupado.',

    /*
     * Cómo se nombra cada campo dentro del mensaje. Sin esto sale
     * «Falta centro_costo», con el guión bajo a la vista.
     */
    'attributes' => [
        'bodega' => 'la bodega',
        'centro_costo' => 'el centro de costo',
        'cliente' => 'el cliente',
        'comentario' => 'el comentario',
        'condicion' => 'la condición de venta',
        'contacto' => 'el contacto',
        'descripcion' => 'la descripción',
        'descuento_pct' => 'el descuento',
        'email' => 'el correo',
        'fecha' => 'la fecha',
        'fecha_entrega' => 'la fecha de entrega',
        'lineas' => 'el detalle',
        'lineas.*.cantidad' => 'la cantidad',
        'lineas.*.precio' => 'el precio',
        'lineas.*.producto' => 'el producto',
        'lista' => 'la lista de precios',
        'moneda' => 'la moneda',
        'motivo' => 'el motivo',
        'nombre' => 'el nombre',
        'observacion' => 'la observación',
        'oc' => 'la orden de compra',
        'rut' => 'el RUT',
        'vendedor' => 'el vendedor',
    ],
];
