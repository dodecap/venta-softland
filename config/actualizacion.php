<?php

return [

    /*
     * De dónde se baja la versión nueva.
     *
     * **Un repositorio y N instalaciones, también al actualizar.** Todas las
     * empresas se actualizan desde el mismo sitio: si cada una tuviera su copia
     * del repositorio, cada arreglo sería un merge por cliente y a los seis
     * meses cada uno correría un código distinto. Lo de cada empresa vive fuera
     * del código y por eso actualizar no lo toca.
     *
     * Se puede apuntar a otro lado —una bifurcación, o un servidor de pruebas—
     * sin tocar código. `api` existe sobre todo para poder ensayar la
     * actualización entera contra un servidor local, sin publicar nada.
     */
    'repositorio' => env('ACTUALIZACION_REPO', 'dodecap/venta-softland'),

    'api' => env('ACTUALIZACION_API', 'https://api.github.com'),

    /*
     * Cómo se llama cada pieza dentro de una publicación.
     *
     * El nombre del paquete de dependencias lleva dentro las primeras letras
     * del sha256 de `composer.lock`. Así el servidor sabe **sin preguntar
     * nada** si tiene que bajarse los 17 MB o puede saltárselos: compara ese
     * trozo con el de su propio `composer.lock`. No hace falta un archivo de
     * manifiesto ni una segunda llamada.
     */
    'piezas' => [
        'servidor' => '-servidor.tar.gz',
        'apk' => '.apk',
        'vendor' => '/^vendor-([0-9a-f]{8,64})\.tgz$/',
    ],

    /*
     * Segundos para bajar. El paquete de dependencias pesa 17 MB y una oficina
     * con ADSL no es rara; el que manda es el plazo, no la impaciencia.
     */
    'plazo' => (int) env('ACTUALIZACION_PLAZO', 600),

];
