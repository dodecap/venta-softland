<?php

namespace Tests\Feature;

use App\Support\SoftlandConfig;
use Tests\TestCase;

class SetupTest extends TestCase
{
    public function test_sin_configurar_todo_redirige_a_setup(): void
    {
        if (SoftlandConfig::exists()) {
            $this->markTestSkipped('Esta instalación ya está configurada.');
        }

        $this->get('/')->assertRedirect('/setup');
        $this->get('/setup')->assertOk();
    }

    public function test_la_api_responde_ping_sin_autenticacion(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJsonStructure(['ok', 'app', 'configurado']);
    }

    public function test_la_api_exige_token(): void
    {
        $this->getJson('/api/bootstrap')->assertStatus(SoftlandConfig::exists() ? 401 : 503);
        $this->getJson('/api/admin/usuarios')->assertStatus(SoftlandConfig::exists() ? 401 : 503);
    }
}
