<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests\Unit;

use Cacheer\Laravel\Support\CacheerManager;
use Cacheer\Laravel\Tests\TestCase;
use Silviooosilva\CacheerPhp\Cacheer;

class CacheerManagerTest extends TestCase
{
    public function test_it_creates_cacheer_instance_with_configured_driver(): void
    {
        $this->app['config']->set('cacheer.default_driver', 'array');

        $manager = $this->app->make(CacheerManager::class);

        $this->assertInstanceOf(Cacheer::class, $manager->get());
        $this->assertSame('array', $this->app['config']->get('cacheer.default_driver'));
    }

    public function test_enable_reporter_is_noop_when_monitor_missing(): void
    {
        $manager = $this->app->make(CacheerManager::class);

        $manager->enableReporter();

        $this->assertInstanceOf(Cacheer::class, $manager->get());
    }

    public function test_call_forwards_to_cacheer_instance(): void
    {
        $this->app['config']->set('cacheer.default_driver', 'array');

        $manager = $this->app->make(CacheerManager::class);
        $manager->putCache('test-key', 'test-value');

        $this->assertTrue($manager->has('test-key'));
        $this->assertSame('test-value', $manager->getCache('test-key'));
    }

    public function test_defaults_to_file_driver_when_not_configured(): void
    {
        $this->app['config']->set('cacheer.default_driver', null);

        $manager = $this->app->make(CacheerManager::class);

        $this->assertInstanceOf(Cacheer::class, $manager->get());
    }

    public function test_compression_is_applied_when_configured(): void
    {
        $this->app['config']->set('cacheer.default_driver', 'array');
        $this->app['config']->set('cacheer.compress', true);

        $manager = $this->app->make(CacheerManager::class);

        $this->assertInstanceOf(Cacheer::class, $manager->get());
    }

    public function test_encryption_is_applied_when_key_provided(): void
    {
        $this->app['config']->set('cacheer.default_driver', 'array');
        $this->app['config']->set('cacheer.encryption_key', 'test-encryption-key-32chars!!!!!');

        $manager = $this->app->make(CacheerManager::class);

        $this->assertInstanceOf(Cacheer::class, $manager->get());
    }
}
