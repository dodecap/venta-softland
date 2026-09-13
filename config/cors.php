<?php

// CORS para la API móvil (Capacitor). La app envía token Bearer (sin cookies),
// por eso allowed_origins '*' es seguro y supports_credentials queda en false.
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
