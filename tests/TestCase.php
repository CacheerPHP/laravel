<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests;

use Cacheer\Laravel\CacheerServiceProvider;
use Cacheer\Laravel\StoreFactory;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;

/**
 * A Laravel application with the package loaded, and helpers to build cacheer stores.
 */
abstract class TestCase extends Orchestra
{
    private ?string $directory = null;

    private int $stores = 0;

    private bool $redisCleared = false;

    /**
     * The package's service provider.
     *
     * @param  Application  $app  The application.
     * @return list<class-string> The providers to load.
     */
    protected function getPackageProviders($app): array
    {
        return [CacheerServiceProvider::class];
    }

    /**
     * Use the `testing` cache prefix and an in-memory SQLite database.
     *
     * @param  Application  $app  The application.
     */
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

    /**
     * Remove the test's temporary directory.
     */
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
     * @param  array<string, mixed>  $config  The store's options, without the driver.
     * @param  string|null  $name  The store name, or null for a new one.
     * @return Repository The store.
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

        // Lock records outlive a prefixed flush, so the first redis store of a
        // test clears the whole test keyspace through an unprefixed store.
        if (($config['backend'] ?? null) === 'redis' && ! $this->redisCleared) {
            $this->redisCleared = true;
            $this->app?->make(StoreFactory::class)->make(['prefix' => ''] + $config)->flush();
        }

        Config::set("cache.stores.{$name}", $config);
        $store = Cache::store($name);
        $this->assertInstanceOf(Repository::class, $store);

        return $store;
    }

    /**
     * A temporary directory for this test, removed afterwards.
     *
     * @return string The directory path.
     */
    protected function directory(): string
    {
        return $this->directory ??= sys_get_temp_dir().'/cacheer-laravel-'.bin2hex(random_bytes(6));
    }

    /**
     * Configure the `cacheer_test` redis connection on database 15.
     */
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
