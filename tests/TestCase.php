<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests;

use Cacheer\Laravel\CacheerLaravelServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [CacheerLaravelServiceProvider::class];
    }
}
