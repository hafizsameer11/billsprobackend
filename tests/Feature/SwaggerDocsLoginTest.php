<?php

namespace Tests\Feature;

use Tests\TestCase;

class SwaggerDocsLoginTest extends TestCase
{
    public function test_swagger_login_form_is_reachable(): void
    {
        $response = $this->get('/docs/login');
        $response->assertOk();
        $response->assertSee('API documentation', false);
    }
}
