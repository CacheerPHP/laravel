<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests\Feature;

use Cacheer\Laravel\CacheerStore;
use Cacheer\Laravel\StoreFactory;
use Cacheer\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Silviooosilva\CacheerPhp\Contracts\Cache;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\CacheEventType;
use Silviooosilva\CacheerPhp\Observability\Telemetry;

/**
 * How the provider and factory turn `config/cache.php` into a CacheerPHP cache.
 */
final class ServiceProviderTest extends TestCase
{
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

    public function test_reads_and_writes_fail_over_while_the_primary_is_down_and_locks_fail_closed(): void
    {
        $this->unreachableRedis();
        $cache = $this->store(['backend' => 'redis', 'connection' => 'down', 'fallback' => 'array']);

        $cache->put('key', 'value', 60);

        $this->assertSame('value', $cache->get('key'));
        $this->assertFalse($this->lockStore($cache->getStore())->lock('job', 60)->get());
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

    public function test_the_migration_creates_every_configured_database_table_once(): void
    {
        Config::set('cache.stores', [
            'one' => ['driver' => 'cacheer', 'backend' => 'database'],
            'two' => ['driver' => 'cacheer', 'backend' => 'redis', 'fallback' => ['backend' => 'database', 'table' => 'cache_fallback']],
            'same' => ['driver' => 'cacheer', 'backend' => 'database', 'table' => 'cacheer_store'],
            'laravel' => ['driver' => 'database', 'table' => 'cache'],
        ]);

        $this->assertSame(
            [[null, 'cacheer_store'], [null, 'cache_fallback']],
            StoreFactory::databaseTables((array) Config::get('cache.stores')),
        );

        $this->assertSame(0, Artisan::call('migrate', [
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true,
        ]));

        $tables = array_column(DB::select("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name');
        foreach (['cacheer_store', 'cacheer_store_locks', 'cacheer_store_tags', 'cache_fallback'] as $table) {
            $this->assertContains($table, $tables);
        }

        CacheFacade::store('one')->put('key', 'value', 60);
        $this->assertSame('value', CacheFacade::store('one')->get('key'));
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
     * @param  array<string, mixed>  $config
     */
    private function cacheerStore(array $config): CacheerStore
    {
        $factory = $this->app?->make(StoreFactory::class);
        $this->assertInstanceOf(StoreFactory::class, $factory);

        return $factory->make($config);
    }

    private function lockStore(mixed $store): CacheerStore
    {
        $this->assertInstanceOf(CacheerStore::class, $store);

        return $store;
    }

    private function unreachableRedis(): void
    {
        Config::set('database.redis.client', 'predis');
        Config::set('database.redis.down', ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2]);
    }

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
