<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests\Feature;

use Cacheer\Laravel\CacheerServiceProvider;
use Cacheer\Laravel\CacheerStore;
use Cacheer\Laravel\LaravelClock;
use Cacheer\Laravel\PruningArrayStore;
use Cacheer\Laravel\StoreFactory;
use Cacheer\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Silviooosilva\CacheerPhp\Contracts\Cache;
use Silviooosilva\CacheerPhp\Exceptions\ValueTooLargeException;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\CacheEventType;
use Silviooosilva\CacheerPhp\Observability\Telemetry;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;

/**
 * How the provider and factory turn `config/cache.php` into a CacheerPHP cache.
 */
final class ServiceProviderTest extends TestCase
{
    /**
     * Remove telemetry listeners added by the test.
     */
    protected function tearDown(): void
    {
        Telemetry::reset();

        parent::tearDown();
    }

    public function test_the_cache_contract_resolves_to_the_default_cacheer_store(): void
    {
        Config::set('cache.default', 'main');
        $this->store(['backend' => 'array'], 'other');
        $this->store(['backend' => 'array', 'prefix' => 'shop'], 'main')->forever('product', 'boots');

        $cache = $this->app?->make(Cache::class);

        $this->assertInstanceOf(Cache::class, $cache);
        $this->assertSame('boots', $cache->get('product'));
    }

    public function test_the_cache_contract_falls_back_to_the_first_cacheer_store(): void
    {
        Config::set('cache.default', 'array');
        $this->store(['backend' => 'array', 'prefix' => 'first'], 'first');
        $this->store(['backend' => 'array', 'prefix' => 'second'], 'second');

        $cache = $this->app?->make(Cache::class);

        $this->assertInstanceOf(Cache::class, $cache);
        $this->assertSame('first', (string) $cache->boundScope());
    }

    public function test_the_cache_contract_needs_a_cacheer_store(): void
    {
        $this->expectException(RuntimeException::class);

        $this->app?->make(Cache::class);
    }

    public function test_without_any_prefix_the_cache_is_not_scoped(): void
    {
        Config::set('cache.prefix', '');
        $store = $this->cacheerStore(['backend' => 'array']);

        $this->assertTrue($store->cache()->boundScope()->isRoot());
        $this->assertSame('', $store->getPrefix());
    }

    public function test_redis_reads_and_writes_fail_over_while_the_primary_is_down_and_locks_fail_closed(): void
    {
        $this->unreachableRedis();
        $cache = $this->store(['backend' => 'redis', 'connection' => 'down', 'fallback' => 'array']);

        $cache->put('key', 'value', 60);

        $this->assertSame('value', $cache->get('key'));
        $this->assertFalse($this->lockStore($cache->getStore())->lock('job', 60)->get());
    }

    public function test_redis_failover_is_reported_to_telemetry(): void
    {
        if (! defined(CacheEventType::class.'::FallbackServed')) {
            $this->markTestSkipped('Failover events need CacheerPHP 6.1.');
        }

        $types = [];
        Telemetry::listen(function (CacheEvent $event) use (&$types): void {
            $types[] = $event->type->value;
        });
        $this->unreachableRedis();

        $this->store(['backend' => 'redis', 'connection' => 'down', 'fallback' => 'array'])->get('key');

        $this->assertContains('cache.fallback_served', $types);
    }

    public function test_a_lock_record_is_read_from_the_shared_store_not_a_servers_local_tier(): void
    {
        $config = ['backend' => 'file', 'path' => $this->directory().'/shared', 'l1' => 60];
        $serverA = $this->cacheerStore($config);
        $serverB = $this->cacheerStore($config);

        $held = $serverB->lock('report');
        $this->assertTrue($held->get());
        $serverA->lock('report')->forceRelease();
        $current = $serverA->lock('report');
        $this->assertTrue($current->get());

        $this->assertFalse($serverB->restoreLock('report', (string) $held->owner())->release());
        $this->assertTrue($current->isOwnedByCurrentProcess());
    }

    public function test_values_are_encrypted_at_rest(): void
    {
        $cache = $this->store([
            'backend' => 'file',
            'path' => $this->directory().'/encrypted',
            'encryption' => ['active' => 'v1', 'passphrases' => ['v1' => 'correct horse battery staple']],
        ]);

        $cache->forever('card', 'secret-value-4242');

        $this->assertSame('secret-value-4242', $cache->get('card'));
        $this->assertStringNotContainsString('secret-value-4242', $this->filesUnder($this->directory().'/encrypted'));
    }

    public function test_an_empty_encryption_passphrase_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('passphrase [v1] is empty');

        $this->cacheerStore(['backend' => 'array', 'encryption' => ['active' => 'v1', 'passphrases' => ['v1' => null]]]);
    }

    public function test_numeric_options_accept_environment_strings(): void
    {
        $store = $this->cacheerStore([
            'backend' => 'file',
            'path' => $this->directory().'/numeric',
            'l1' => '60',
            'compress' => '6',
            'max_value_bytes' => '64',
        ]);

        $this->assertTrue($store->put('small', 'value', 60));
        $this->assertSame('value', $store->get('small'));

        $this->expectException(ValueTooLargeException::class);
        $store->put('large', str_repeat('x', 1000), 60);
    }

    /** @return array<string, array{string, mixed}> */
    public static function disabledOptions(): array
    {
        return [
            'l1 zero' => ['l1', '0'],
            'l1 unset variable' => ['l1', ''],
            'compress zero' => ['compress', '0'],
            'compress false' => ['compress', false],
            'max_value_bytes zero' => ['max_value_bytes', '0'],
        ];
    }

    #[DataProvider('disabledOptions')]
    public function test_numeric_options_can_be_switched_off(string $option, mixed $value): void
    {
        $store = $this->cacheerStore(['backend' => 'array', $option => $value]);

        $this->assertTrue($store->put('key', 'value', 60));
        $this->assertSame('value', $store->get('key'));
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidOptions(): array
    {
        return [
            'l1 word' => ['l1', 'sixty'],
            'l1 negative' => ['l1', -5],
            'l1 fraction' => ['l1', '1.5'],
            'compress above 9' => ['compress', 10],
            'compress word' => ['compress', 'max'],
            'max_value_bytes word' => ['max_value_bytes', 'abc'],
            'max_value_bytes negative' => ['max_value_bytes', '-1'],
        ];
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_numeric_options_are_refused_by_name(string $option, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("CacheerPHP {$option} is");

        $this->cacheerStore(['backend' => 'array', $option => $value]);
    }

    public function test_a_prefix_that_cannot_be_a_scope_is_refused_by_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The cache prefix [acme/app]');

        $this->cacheerStore(['backend' => 'array', 'prefix' => 'acme/app']);
    }

    public function test_in_process_copies_are_swept_once_they_expire(): void
    {
        $clock = new LaravelClock;
        $entries = new ArrayStore($clock);
        $store = new PruningArrayStore($entries, $clock);
        $fill = function () use ($store): void {
            foreach (range(1, 100) as $i) {
                $store->set(Key::named("key{$i}"), 'value', Ttl::seconds(1));
            }
        };

        // Sweeping runs at most once a minute, not on every operation.
        $fill();
        $this->travel(2)->seconds();
        $store->get(Key::named('other'));
        $this->assertSame(100, $entries->prune());

        $fill();
        $this->travel(61)->seconds();
        $store->get(Key::named('other'));
        $this->assertSame(0, $entries->prune());
    }

    public function test_an_unknown_backend_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown CacheerPHP backend [memcached]');

        $this->cacheerStore(['backend' => 'memcached']);
    }

    public function test_a_redis_cluster_is_refused(): void
    {
        Config::set('database.redis.clusters.shared', [['host' => '127.0.0.1', 'port' => 7000]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Redis clusters are not supported');

        $this->cacheerStore(['backend' => 'redis', 'connection' => 'shared']);
    }

    /** @return array<string, array{string}> */
    public static function redisOptions(): array
    {
        return [
            'global options' => ['global'],
            'connection options' => ['connection'],
            'nested connection options' => ['nested'],
            'URL query options' => ['url'],
        ];
    }

    #[DataProvider('redisOptions')]
    public function test_redis_wire_options_do_not_change_cacheer_keys_or_values(string $location): void
    {
        if (getenv('CACHEER_TEST_REDIS_PORT') === false) {
            $this->markTestSkipped('A disposable Redis server is not configured.');
        }

        $this->useRedis();
        $options = ['prefix' => 'foreign:', 'serializer' => 1, 'compression' => 1, 'compression_level' => 1];

        match ($location) {
            'global' => Config::set('database.redis.options', $options),
            'connection' => Config::set('database.redis.cacheer_test', (array) Config::get('database.redis.cacheer_test') + $options),
            'nested' => Config::set('database.redis.cacheer_test.options', $options),
            'url' => Config::set('database.redis.cacheer_test.url', sprintf(
                'redis://%s:%d/15?%s',
                getenv('CACHEER_TEST_REDIS_HOST') ?: '127.0.0.1',
                (int) getenv('CACHEER_TEST_REDIS_PORT'),
                http_build_query($options + ['options' => $options]),
            )),
            default => throw new InvalidArgumentException('Unknown Redis test option location.'),
        };

        $store = $this->cacheerStore([
            'backend' => 'redis', 'connection' => 'cacheer_test',
            'redis_prefix' => 'cacheer_laravel_options', 'prefix' => $location,
        ]);
        $store->put('key', ['value' => 42], 60);
        $this->assertSame(['value' => 42], $store->get('key'));
        $this->assertTrue($store->lock('job', 60)->get());
        $store->lock('job')->forceRelease();
        $store->flush();
        $this->assertNull($store->get('key'));
    }

    public function test_the_published_migration_rolls_back_its_original_tables(): void
    {
        Config::set('cache.stores', [
            'one' => ['driver' => 'cacheer', 'backend' => 'database'],
        ]);
        $this->assertNotNull($this->app);
        $this->app->useDatabasePath($this->directory().'/database');
        (new CacheerServiceProvider($this->app))->boot();
        $this->assertSame(0, Artisan::call('vendor:publish', ['--tag' => 'cacheer-migrations']));
        $files = glob($this->app->databasePath('migrations/*_create_cacheer_store_tables.php'));
        $this->assertIsArray($files);
        $this->assertCount(1, $files);

        $this->assertSame(0, Artisan::call('migrate', [
            '--path' => $this->app->databasePath('migrations'),
            '--realpath' => true,
        ]));

        $tables = array_column(DB::select("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name');
        foreach (['cacheer_store', 'cacheer_store_locks', 'cacheer_store_tags'] as $table) {
            $this->assertContains($table, $tables);
        }

        CacheFacade::store('one')->put('key', 'value', 60);
        $this->assertSame('value', CacheFacade::store('one')->get('key'));

        DB::statement('CREATE TABLE unrelated (id INTEGER)');
        DB::statement('INSERT INTO unrelated (id) VALUES (42)');
        Config::set('cache.stores.one.table', 'unrelated');
        $this->assertSame(0, Artisan::call('migrate:rollback', [
            '--path' => $this->app->databasePath('migrations'),
            '--realpath' => true,
        ]));
        $this->assertSame(42, (int) DB::selectOne('SELECT id FROM unrelated')->id);
        $tables = array_column(DB::select("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name');
        foreach (['cacheer_store', 'cacheer_store_locks', 'cacheer_store_tags'] as $table) {
            $this->assertNotContains($table, $tables);
        }
    }

    public function test_cache_operations_reach_cacheerphp_telemetry(): void
    {
        $events = [];
        Telemetry::listen(function (CacheEvent $event) use (&$events): void {
            $events[] = $event->type;
        });

        $cache = $this->store(['backend' => 'array']);
        $cache->put('key', 'value', 60);
        $cache->get('key');

        $this->assertContains(CacheEventType::Hit, $events);
    }

    /**
     * Build a store straight from the factory.
     *
     * @param  array<string, mixed>  $config  The store's options.
     * @return CacheerStore The store.
     */
    private function cacheerStore(array $config): CacheerStore
    {
        $factory = $this->app?->make(StoreFactory::class);
        $this->assertInstanceOf(StoreFactory::class, $factory);

        return $factory->make($config);
    }

    /**
     * Assert that a store is a cacheer store.
     *
     * @param  mixed  $store  The store.
     * @return CacheerStore The same store.
     */
    private function lockStore(mixed $store): CacheerStore
    {
        $this->assertInstanceOf(CacheerStore::class, $store);

        return $store;
    }

    /**
     * Configure a `down` redis connection that nothing listens on.
     */
    private function unreachableRedis(): void
    {
        Config::set('database.redis.client', getenv('CACHEER_TEST_REDIS_CLIENT') ?: 'predis');
        Config::set('database.redis.down', ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2]);
    }

    /**
     * Concatenate every file under a directory.
     *
     * @param  string  $directory  The directory.
     * @return string The files' contents.
     */
    private function filesUnder(string $directory): string
    {
        $contents = '';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $contents .= (string) file_get_contents($file->getPathname());
            }
        }

        return $contents;
    }
}
