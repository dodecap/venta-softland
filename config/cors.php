<?php

// CORS para la API móvil (Capacitor). La app envía token Bearer (sin cookies),
// por eso allowed_origins '*' es seguro y supports_credentials queda en false.
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    // Sin esto, `fetch` no deja leer estas dos desde la app: con
    // `allow-origin: *` el navegador sólo entrega las siete cabeceras de la
    // lista segura, y las demás las esconde sin avisar. El teléfono guardaba
    // entonces todos los PDF como «versión 1, sin huella» y no tenía forma de
    // saber si el que tiene archivado sigue siendo el vigente.
    'exposed_headers' => ['X-Documento-Version', 'X-Documento-Hash'],
    'max_age' => 0,
    'supports_credentials' => false,
];
