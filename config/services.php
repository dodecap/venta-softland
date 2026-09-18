<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
    ],

    /*
     * La consulta al padrón público del SII, que llena el formulario de alta de
     * clientes. La llave vive en el `.env` del servidor y no sale de ahí: el
     * teléfono nunca llama a esta API, la llama el servidor. Ver
     * `docs/alta-clientes-sii.md`.
     */
    'sii_aux' => [
        'url' => env('SII_AUX_URL'),
        'key' => env('SII_AUX_KEY'),
        // La ficha de una empresa no cambia de un día para otro; lo que sí pasa
        // es que el padrón se reconstruye por tandas.
        'dias' => (int) env('SII_AUX_DIAS', 30),
        // Y un RUT que el padrón todavía no tiene se vuelve a preguntar antes:
        // una empresa nueva aparece cuando el SII la publica.
        'dias_sin_hallar' => (int) env('SII_AUX_DIAS_SIN_HALLAR', 7),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
