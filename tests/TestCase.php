<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests;

use Cacheer\Laravel\CacheerServiceProvider;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;

abstract class TestCase extends Orchestra
{
    private ?string $directory = null;

    private int $stores = 0;

    protected function getPackageProviders($app): array
    {
        return [CacheerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.prefix', 'testing');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            (new Filesystem)->deleteDirectory($this->directory);
            $this->directory = null;
        }

        parent::tearDown();
    }

    /**
     * Backend configurations every store test runs against. Redis joins only
     * when a disposable server is named through CACHEER_TEST_REDIS_PORT; it uses
     * database 15 and its own key prefix, never an application's keyspace.
     * CACHEER_TEST_REDIS_CLIENT picks predis (default) or phpredis.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function backends(): array
    {
        $backends = [
            'array' => [['backend' => 'array']],
            'file' => [['backend' => 'file']],
            'database' => [['backend' => 'database']],
        ];

        if (getenv('CACHEER_TEST_REDIS_PORT') !== false) {
            $backends['redis'] = [['backend' => 'redis', 'connection' => 'cacheer_test']];
        }

        return $backends;
    }

    /**
     * A fresh cacheer store under its own name, with the backend prepared
     * (temporary directory, schema, isolated redis keyspace).
     *
     * @param  array<string, mixed>  $config
     */
    protected function store(array $config, ?string $name = null): Repository
    {
        $name ??= 'cacheer_'.++$this->stores;
        $config = ['driver' => 'cacheer'] + $config;

        if (($config['backend'] ?? 'file') === 'file') {
            $config['path'] ??= $this->directory().'/'.$name;
        }

        if (($config['backend'] ?? null) === 'database') {
            DatabaseStoreSchema::migrate(DB::connection()->getPdo(), 'cacheer_store');
        }

        if (($config['backend'] ?? null) === 'redis') {
            $this->useRedis();
            $config['redis_prefix'] ??= 'cacheer_laravel_test';
        }

        Config::set("cache.stores.{$name}", $config);
        $store = Cache::store($name);
        $this->assertInstanceOf(Repository::class, $store);

        if (($config['backend'] ?? null) === 'redis') {
            $store->flush();
        }

        return $store;
    }

    protected function directory(): string
    {
        return $this->directory ??= sys_get_temp_dir().'/cacheer-laravel-'.bin2hex(random_bytes(6));
    }

    protected function useRedis(): void
    {
        Config::set('database.redis.client', getenv('CACHEER_TEST_REDIS_CLIENT') ?: 'predis');
        Config::set('database.redis.cacheer_test', [
            'host' => getenv('CACHEER_TEST_REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) getenv('CACHEER_TEST_REDIS_PORT'),
            'database' => 15,
        ]);
    }
}
