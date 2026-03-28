<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests\Feature;

use Cacheer\Laravel\Tests\TestCase;

class DiagnosticsCommandTest extends TestCase
{
    public function test_diagnostics_displays_configuration(): void
    {
        $this->artisan('cacheer:diagnostics')
            ->assertExitCode(0);
    }

    public function test_diagnostics_shows_configured_driver(): void
    {
        $this->app['config']->set('cacheer.default_driver', 'array');

        $this->artisan('cacheer:diagnostics')
            ->expectsOutputToContain('array')
            ->assertExitCode(0);
    }

    public function test_diagnostics_shows_compression_status(): void
    {
        $this->app['config']->set('cacheer.compress', true);

        $this->artisan('cacheer:diagnostics')
            ->expectsOutputToContain('enabled')
            ->assertExitCode(0);
    }

    public function test_diagnostics_shows_reporter_disabled(): void
    {
        $this->app['config']->set('cacheer.reporter.enabled', false);

        $this->artisan('cacheer:diagnostics')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);
    }
}
