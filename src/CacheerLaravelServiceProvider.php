<?php

declare(strict_types=1);

namespace Cacheer\Laravel;

use Cacheer\Laravel\Console\Commands\DiagnosticsCommand;
use Cacheer\Laravel\Console\Commands\EventsClearCommand;
use Cacheer\Laravel\Console\Commands\MonitorServeCommand;
use Cacheer\Laravel\Support\CacheerManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class CacheerLaravelServiceProvider extends ServiceProvider
{
    /**
     * Register bindings and configuration.
     */
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cacheer.php', 'cacheer');

        $this->app->singleton(CacheerManager::class, fn (Application $app) => new CacheerManager($app));

        $this->app->alias(CacheerManager::class, 'cacheer');

        if ($this->app['config']->get('cacheer.reporter.enabled')) {
            $this->app->resolving(CacheerManager::class, function (CacheerManager $manager): void {
                $manager->enableReporter();
            });
        }
    }

    /**
     * Bootstrap resources and commands.
     */
    public function boot(): void
    {
        $configPath = function_exists('config_path')
            ? config_path('cacheer.php')
            : $this->app->basePath('config/cacheer.php');

        $this->publishes([
            __DIR__.'/../config/cacheer.php' => $configPath,
        ], 'cacheer-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MonitorServeCommand::class,
                EventsClearCommand::class,
                DiagnosticsCommand::class,
            ]);
        }
    }
}
