<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests\Feature;

use Cacheer\Laravel\Support\CacheerManager;
use Cacheer\Laravel\Tests\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;

class ServiceProviderTest extends TestCase
{
    public function test_it_binds_cacheer_manager(): void
    {
        $manager = $this->app->make(CacheerManager::class);
        $this->assertInstanceOf(CacheerManager::class, $manager);
        $this->assertInstanceOf(Cacheer::class, $manager->get());
    }

    public function test_cacheer_alias_resolves_to_manager(): void
    {
        $manager = $this->app->make('cacheer');
        $this->assertInstanceOf(CacheerManager::class, $manager);
    }

    public function test_manager_is_singleton(): void
    {
        $first = $this->app->make(CacheerManager::class);
        $second = $this->app->make(CacheerManager::class);
        $this->assertSame($first, $second);
    }

    public function test_configuration_is_merged(): void
    {
        $config = $this->app['config']->get('cacheer');
        $this->assertIsArray($config);
        $this->assertArrayHasKey('default_driver', $config);
        $this->assertArrayHasKey('cache_dir', $config);
        $this->assertArrayHasKey('compress', $config);
        $this->assertArrayHasKey('reporter', $config);
        $this->assertArrayHasKey('monitor', $config);
    }

    public function test_commands_are_registered(): void
    {
        $this->artisan('cacheer:diagnostics')->assertExitCode(0);
    }
}
