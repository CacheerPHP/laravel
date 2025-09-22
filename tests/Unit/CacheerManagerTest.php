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
}
