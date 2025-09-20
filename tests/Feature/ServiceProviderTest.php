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
}
