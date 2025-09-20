<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Support;

use Illuminate\Contracts\Foundation\Application;
use Silviooosilva\CacheerPhp\Cacheer;

class CacheerManager
{
    private Cacheer $instance;
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->instance = $this->createInstance();
    }

    public function get(): Cacheer
    {
        return $this->instance;
    }

    public function __call(string $method, array $arguments)
    {
        return $this->instance->{$method}(...$arguments);
    }

    public function enableReporter(): void
    {
        $instrumentedClass = '\\Cacheer\\Monitor\\InstrumentedCacheer';
        $reporterClass = '\\Cacheer\\Monitor\\Reporter\\JsonlReporter';

        if (!class_exists($instrumentedClass) || !class_exists($reporterClass)) {
            return;
        }

        $eventsFile = $this->app['config']->get('cacheer.reporter.events');
        $reporter = new $reporterClass($eventsFile ? (string) $eventsFile : null);

        $this->instance = $instrumentedClass::wrap($this->instance, $reporter);
    }

    private function createInstance(): Cacheer
    {
        $config = $this->app['config']->get('cacheer', []);
        $driver = (string) ($config['default_driver'] ?? 'file');
        $options = ['cacheDir' => $config['cache_dir'] ?? storage_path('cacheer')];
        $cacheer = new Cacheer($options);
        $this->applyDriver($cacheer, $driver);

        if (!empty($config['compress'])) {
            $cacheer->useCompression(true);
        }

        if (!empty($config['encryption_key'])) {
            $cacheer->useEncryption((string) $config['encryption_key']);
        }

        return $cacheer;
    }

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
