<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Support;

use Illuminate\Contracts\Foundation\Application;
use Silviooosilva\CacheerPhp\Cacheer;

class CacheerManager
{
    /**
     * Underlying Cacheer instance.
     */
    private Cacheer $instance;

    /**
     * The Laravel application container instance.
     */
    private Application $app;

    /**
     * Create a new manager.
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->instance = $this->createInstance();
    }

    /**
     * Get the current Cacheer instance.
     */
    public function get(): Cacheer
    {
        return $this->instance;
    }

    /**
     * Forward calls to the Cacheer instance.
     */
    public function __call(string $method, array $arguments)
    {
        return $this->instance->{$method}(...$arguments);
    }

    /**
     * Enable the Jsonl reporter when the monitor package is installed.
     */
    public function enableReporter(): void
    {
        $instrumentedClass = '\\Cacheer\\Monitor\\InstrumentedCacheer';
        $reporterClass = '\\Cacheer\\Monitor\\Reporter\\JsonlReporter';

        if (! class_exists($instrumentedClass) || ! class_exists($reporterClass)) {
            return;
        }

        $eventsFile = $this->app['config']->get('cacheer.reporter.events');
        $reporter = new $reporterClass($eventsFile ? (string) $eventsFile : null);

        $this->instance = $instrumentedClass::wrap($this->instance, $reporter);
    }

    /**
     * Build a Cacheer instance according to configuration values.
     */
    private function createInstance(): Cacheer
    {
        $config = $this->app['config']->get('cacheer', []);
        $driver = (string) ($config['default_driver'] ?? 'file');
        $options = ['cacheDir' => $config['cache_dir'] ?? storage_path('cacheer')];
        $cacheer = new Cacheer($options);
        $this->applyDriver($cacheer, $driver);

        if (! empty($config['compress'])) {
            $cacheer->useCompression(true);
        }

        if (! empty($config['encryption_key'])) {
            $cacheer->useEncryption((string) $config['encryption_key']);
        }

        return $cacheer;
    }

    /**
     * Apply the configured driver to the Cacheer instance.
     */
    private function applyDriver(Cacheer $cacheer, string $driver): void
    {
        $driver = strtolower($driver);
        $driverManager = $cacheer->setDriver();
        $map = [
            'redis' => 'useRedisDriver',
            'database' => 'useDatabaseDriver',
            'db' => 'useDatabaseDriver',
            'array' => 'useArrayDriver',
            'file' => 'useFileDriver',
            'default' => 'useDefaultDriver',
        ];

        if (isset($map[$driver])) {
            $driverManager->{$map[$driver]}();

            return;
        }

        $driverManager->useDefaultDriver();
    }
}
