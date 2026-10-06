<?php
/*
 * El puente, y no la aplicación.
 *
 * La aplicación arranca en `public/index.php`, que es donde Laravel la pone y
 * donde sigue estando. Este archivo existe para una sola cosa: que descomprimir
 * el paquete dentro de `C:\xampp\htdocs` y abrir el navegador **baste**, sin
 * editar `httpd.conf` ni reiniciar Apache. Ver el `.htaccess` de al lado.
 *
 * ## Por qué un puente y no un rewrite a `public/`
 *
 * Porque lo que Apache diga a PHP en `SCRIPT_NAME` es lo que Laravel usa para
 * saber dónde vive. Rewriteando a `public/index.php`, `SCRIPT_NAME` acaba en
 * `/venta-softland/public/index.php`, y Laravel escribe direcciones con un
 * `/public` dentro que no abren. Entrando por aquí, `SCRIPT_NAME` es
 * `/venta-softland/index.php` y la carpeta que Laravel deduce es la buena.
 *
 * Es el único archivo del proyecto que se sirve por su nombre; el `.htaccess`
 * de al lado deniega todos los demás `.php`.
 */

require __DIR__.'/public/index.php';
