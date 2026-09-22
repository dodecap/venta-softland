<?php

return [

    /*
     * El certificado digital de la empresa: el que firma el DTE y el sobre.
     *
     * Es cosa distinta del CAF. El CAF timbra el folio y vive en la base de
     * Softland; el certificado acredita quién emite y vive en un archivo, fuera
     * de git, junto al `softland.json`.
     *
     * **Desde la 0.46.0 se sube desde la app**, en Configuración → Certificado
     * digital, y queda en `almacen` con su clave cifrada (`AlmacenCertificado`).
     * Era lo único de toda la instalación que obligaba a abrir una sesión en el
     * servidor y editar un archivo, y no una vez: el certificado se renueva
     * todos los años.
     *
     * `ruta` y `clave` quedan de **respaldo**, para las instalaciones que ya lo
     * tenían puesto así: siguen emitiendo sin que nadie haga nada. Lo subido
     * manda sobre esto, porque es lo que alguien decidió después.
     */
    'certificado' => [
        'ruta' => env('DTE_CERT_RUTA', storage_path('app/private/certificado.pfx')),
        'clave' => env('DTE_CERT_CLAVE'),
        // Dónde guarda `AlmacenCertificado` lo que se sube. Configurable para
        // poder probarlo sin escribir donde está el de verdad.
        'almacen' => storage_path('app/private'),
    ],

    /*
     * Dónde habla el SII. Las direcciones salieron de la propia instalación de
     * Softland (`Softland.Sii.config`), no de un manual.
     *
     * `certificacion` es maullin. **Para INNOVAGES ya no sirve**: cerrada la
     * declaración de cumplimiento, el SII cierra ese ambiente para el RUT. Queda
     * declarado porque una instalación nueva sí pasa por ahí.
     *
     * La boleta no viaja por donde la factura: va por una API REST aparte.
     */
    'ambiente' => env('DTE_AMBIENTE', 'produccion'),

    'ambientes' => [
        'certificacion' => [
            'semilla' => 'https://maullin.sii.cl/DTEWS/CrSeed.jws',
            'token' => 'https://maullin.sii.cl/DTEWS/GetTokenFromSeed.jws',
            'envio' => 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload',
            'estado_envio' => 'https://maullin.sii.cl/DTEWS/QueryEstUp.jws',
            'estado_dte' => 'https://maullin.sii.cl/DTEWS/QueryEstDte.jws',
            'boleta' => 'https://apicert.sii.cl/recursos/v1',
        ],
        'produccion' => [
            'semilla' => 'https://palena.sii.cl/DTEWS/CrSeed.jws',
            'token' => 'https://palena.sii.cl/DTEWS/GetTokenFromSeed.jws',
            'envio' => 'https://palena.sii.cl/cgi_dte/UPL/DTEUpload',
            'estado_envio' => 'https://palena.sii.cl/DTEWS/QueryEstUp.jws',
            'estado_dte' => 'https://palena.sii.cl/DTEWS/QueryEstDte.jws',
            'boleta' => 'https://api.sii.cl/recursos/v1',
        ],
    ],

];
