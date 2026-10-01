<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApplicationBootTest extends TestCase
{
    public function test_the_application_boots(): void
    {
        $this->assertTrue($this->app->isBooted());
        $this->assertSame('testing', $this->app->environment());
    }

    public function test_application_configuration_loads(): void
    {
        $this->assertSame('Accounting Web App', config('app.name'));
        $this->assertNotEmpty(config('app.key'));
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('utf8mb4', config('database.connections.mysql.charset'));
        $this->assertSame('InnoDB', config('database.connections.mysql.engine'));
    }

    public function test_the_api_route_file_is_registered(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->all();

        $this->assertContains('api/health', $uris);
    }
}
