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

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cacheer.cache_dir', $app->storagePath('cacheer-tests'));
        $app['config']->set('cacheer.default_driver', 'file');
        $app['config']->set('cacheer.reporter.enabled', false);
    }
}
