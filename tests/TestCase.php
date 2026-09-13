<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * En producción `APP_URL` lleva la subcarpeta del Alias de Apache
     * (`/venta-softland`), y el despliegue la hornea con `config:cache`. Esa
     * caché también manda en las pruebas: sin esto, `$this->get('/api/ping')`
     * pide `/venta-softland/api/ping` contra un Laravel que no tiene Alias que
     * recortar, y todo responde 404.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
    }
}
