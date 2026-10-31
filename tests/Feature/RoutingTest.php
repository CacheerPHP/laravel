<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests\Feature;

use Cacheer\Laravel\CacheerStore;
use Cacheer\Laravel\Routing\CacheerCacheManager;
use Cacheer\Laravel\Routing\LockFlushingRoutedStore;
use Cacheer\Laravel\Routing\RoutedStore;
use Cacheer\Laravel\Routing\TaggableLockFlushingRoutedStore;
use Cacheer\Laravel\Routing\TaggableRoutedStore;
use Cacheer\Laravel\Tests\Support\LooselyTypedStore;
use Cacheer\Laravel\Tests\Support\RefusingStore;
use Cacheer\Laravel\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\CanFlushLocks;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use RuntimeException;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\Telemetry;

/**
 * Laravel's own stores, routed through CacheerPHP: same results, same data,
 * and the framework features that depend on them.
 */
final class RoutingTest extends TestCase
{
    /**
     * Remove telemetry listeners added by the test.
     */
    protected function tearDown(): void
    {
        Telemetry::reset();

        parent::tearDown();
    }

    /**
     * Laravel stores every routing test runs against. Redis joins when a
     * disposable server is named through CACHEER_TEST_REDIS_PORT.
     *
     * @return array<string, array{string}>
     */
    public static function nativeStores(): array
    {
        $stores = ['file' => ['file'], 'database' => ['database']];

        if (getenv('CACHEER_TEST_REDIS_PORT') !== false) {
            $stores['redis'] = ['redis'];
        }

        return $stores;
    }

    #[DataProvider('nativeStores')]
    public function test_laravel_stores_are_routed_with_their_interfaces_mirrored(string $driver): void
    {
        $store = Cache::store($this->nativeStore($driver))->getStore();
        $flushesLocks = interface_exists(CanFlushLocks::class);

        $this->assertInstanceOf(match (true) {
            $driver === 'redis' => $flushesLocks ? TaggableLockFlushingRoutedStore::class : TaggableRoutedStore::class,
            default => $flushesLocks ? LockFlushingRoutedStore::class : RoutedStore::class,
        }, $store);
        $this->assertSame(CacheerCacheManager::ROUTED, $this->manager()->routing()['native_'.$driver]);
    }

    #[DataProvider('nativeStores')]
    public function test_results_and_laravel_events_match_the_native_store(string $driver): void
    {
        $name = $this->nativeStore($driver);

        [$nativeResults, $nativeEvents] = $this->scenario($this->native($name));
        [$routedResults, $routedEvents] = $this->scenario($this->routed($name));

        $this->assertSame($nativeResults, $routedResults);
        $this->assertSame($nativeEvents, $routedEvents);
    }

    #[DataProvider('nativeStores')]
    public function test_entries_are_shared_with_the_native_store(string $driver): void
    {
        $name = $this->nativeStore($driver);
        $native = $this->native($name);
        $routed = $this->routed($name);
        $routed->flush();

        $routed->put('written:routed', ['id' => 1], 60);
        $routed->putMany([7 => 'seven'], 60);
        $routed->forever('kept', 'forever');
        $native->put('written:native', 'native', 60);

        $this->assertSame(['id' => 1], $native->get('written:routed'));
        $this->assertSame('seven', $native->get('7'));
        $this->assertSame('forever', $native->get('kept'));
        $this->assertSame('native', $routed->get('written:native'));

        $this->assertTrue($routed->forget('written:native'));
        $this->assertNull($native->get('written:native'));
    }

    public function test_operations_are_reported_to_cacheerphp_telemetry(): void
    {
        $events = [];
        Telemetry::listen(function (CacheEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $cache = Cache::store($this->nativeStore('file'));

        $cache->get('missing');
        $cache->put('key', 'value', 60);
        $cache->get('key');
        $cache->increment('counter');

        $this->assertSame(
            ['cache.miss', 'cache.write', 'cache.hit', 'cache.write'],
            array_map(fn (CacheEvent $event): string => $event->type->value, $events),
        );
        $this->assertSame(['LaravelStore'], array_values(array_unique(array_map(fn (CacheEvent $event): string => $event->store, $events))));
        $this->assertSame('increment', $events[3]->operation);
        $this->assertGreaterThan(0.0, $events[1]->durationMicros);
    }

    #[DataProvider('nativeStores')]
    public function test_framework_features_run_on_a_routed_store(string $driver): void
    {
        Config::set('cache.default', $this->nativeStore($driver));
        Queue::fake();

        $lock = Cache::lock('report', 60);
        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::lock('report', 60)->get());
        $this->assertTrue(Cache::restoreLock('report', (string) $lock->owner())->release());

        RoutedUniqueReport::dispatch();
        RoutedUniqueReport::dispatch();
        Queue::assertPushed(RoutedUniqueReport::class, 1);

        $limiter = $this->app?->make(RateLimiter::class);
        $this->assertInstanceOf(RateLimiter::class, $limiter);
        $this->assertTrue($limiter->attempt('login', 1, fn (): bool => true));
        $this->assertFalse($limiter->attempt('login', 1, fn (): bool => true));

        $this->assertNotNull($this->app);
        $event = $this->app->make(Schedule::class)->command('inspire')->withoutOverlapping();
        $mutex = new CacheEventMutex($this->app->make(Factory::class));
        $this->assertTrue($mutex->create($event));
        $this->assertFalse($mutex->create($event));
        $mutex->forget($event);

        if (Cache::supportsTags()) {
            Cache::tags(['users'])->put('ada', 'Ada', 60);
            Cache::tags(['users'])->flush();
            $this->assertNull(Cache::tags(['users'])->get('ada'));
        }
    }

    #[DataProvider('nativeStores')]
    #[RequiresMethod(CacheManager::class, 'memo')]
    public function test_a_memoized_store_wraps_a_routed_store(string $driver): void
    {
        $name = $this->nativeStore($driver);

        $this->assertSame('memoized', Cache::memo($name)->remember('memo', 60, fn (): string => 'memoized'));
        $this->assertSame('memoized', $this->routed($name)->get('memo'));
    }

    public function test_a_native_false_and_a_native_exception_come_back_unchanged(): void
    {
        $native = new RefusingStore;
        Cache::extend('refusing', fn (): Repository => Cache::repository($native));
        Config::set('cache.stores.refusing', ['driver' => 'refusing']);
        $cache = $this->routed('refusing');

        $this->assertInstanceOf(RoutedStore::class, $cache->getStore());
        $this->assertFalse($cache->put('key', 'value', 60));
        $this->assertFalse($cache->putMany(['key' => 'value'], 60));
        $this->assertFalse($cache->forever('key', 'value'));
        $this->assertFalse($cache->flush());

        $native->failure = new RuntimeException('The native store is down.');
        $this->expectExceptionObject($native->failure);

        $cache->get('key');
    }

    public function test_a_custom_stores_own_results_come_back(): void
    {
        Cache::extend('loose', fn (): Repository => Cache::repository(new LooselyTypedStore));
        Config::set('cache.stores.loose', ['driver' => 'loose']);
        $routed = $this->routed('loose');
        $this->assertNotNull($this->app);
        $native = (new CacheManager($this->app))->repository(new LooselyTypedStore);

        foreach ([
            fn (Repository $cache): mixed => $cache->increment('n'),
            fn (Repository $cache): mixed => $cache->decrement('n'),
            fn (Repository $cache): mixed => $cache->getStore()->touch('k', 60),
            fn (Repository $cache): mixed => $cache->add('k', 'v', 60),
            fn (Repository $cache): mixed => $cache->getStore()->put('k', 'v', 0),
        ] as $call) {
            $this->assertSame($call($native), $call($routed));
        }

        // Writes through the engine report a boolean.
        $this->assertFalse($routed->put('k', 'v', 60));
        $this->assertSame('v', $routed->get('k'));
        $this->assertFalse($routed->forget('k'));
        $this->assertNull($routed->get('k'));
    }

    public function test_cloning_a_routed_repository_clones_its_native_store(): void
    {
        Cache::extend('loose', fn (): Repository => Cache::repository(new LooselyTypedStore));
        Config::set('cache.stores.loose', ['driver' => 'loose']);
        $original = $this->routed('loose');

        $copy = clone $original;
        $store = $copy->getStore();
        $this->assertInstanceOf(RoutedStore::class, $store);
        $native = $store->native();
        $this->assertInstanceOf(LooselyTypedStore::class, $native);
        $native->setPrefix('b:');
        $copy->forever('key', 'copy');

        $this->assertSame('a:', $original->getStore()->getPrefix());
        $this->assertNull($original->get('key'));
        $this->assertSame('copy', $copy->get('key'));
    }

    public function test_a_cache_binding_that_is_not_laravels_manager_is_left_alone(): void
    {
        $this->assertNotNull($this->app);
        $factory = new class implements Factory
        {
            public function store($name = null): Repository
            {
                return new Repository(new LooselyTypedStore);
            }
        };
        $this->app->forgetInstance('cache');
        $this->app->singleton('cache', fn (): Factory => $factory);

        $this->assertSame($factory, $this->app->make('cache'));
    }

    public function test_telemetry_is_decided_when_the_store_is_built(): void
    {
        $cache = Cache::store($this->nativeStore('file'));
        $events = [];
        Telemetry::listen(function (CacheEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $cache->put('key', 'value', 60);
        $cache->increment('counter');

        $this->assertSame([], $events);
    }

    public function test_stores_routing_cannot_mirror_are_left_native_with_a_reason(): void
    {
        Config::set('cache.stores.cacheer', ['driver' => 'cacheer', 'backend' => 'array']);
        Config::set('cache.stores.opted_out', ['driver' => 'file', 'path' => $this->directory().'/opted-out', 'cacheer' => false]);
        Config::set('database.redis.client', 'phpredis');
        Config::set('database.redis.serialized', ['host' => '127.0.0.1', 'port' => 6379, 'options' => ['serializer' => 1]]);
        Config::set('cache.stores.serialized_redis', ['driver' => 'redis', 'connection' => 'serialized']);

        $this->assertInstanceOf(ArrayStore::class, Cache::store('array')->getStore());
        $this->assertInstanceOf(CacheerStore::class, Cache::store('cacheer')->getStore());
        $this->assertInstanceOf(FileStore::class, Cache::store('opted_out')->getStore());
        $this->assertInstanceOf(RedisStore::class, Cache::store('serialized_redis')->getStore());

        $routing = $this->manager()->routing();
        $this->assertSame('it lacks add() or locks, which routing must mirror exactly', $routing['array']);
        $this->assertSame('the cacheer driver already runs on CacheerPHP', $routing['cacheer']);
        $this->assertSame("turned off in the store's configuration", $routing['opted_out']);
        $this->assertStringContainsString('phpredis serialization', $routing['serialized_redis']);
    }

    public function test_routing_can_be_turned_off_for_every_store(): void
    {
        Config::set('cache.cacheer.route', false);

        $this->assertInstanceOf(FileStore::class, Cache::store($this->nativeStore('file'))->getStore());
        $this->assertSame('routing is turned off (cache.cacheer.route)', $this->manager()->routing()['native_file']);
    }

    public function test_another_packages_cache_manager_is_left_in_place(): void
    {
        $this->assertNotNull($this->app);
        $manager = new class($this->app) extends CacheManager {};

        $this->assertSame($manager, CacheerCacheManager::replacing($manager, $this->app));
    }

    public function test_custom_drivers_registered_before_routing_are_kept_and_routed(): void
    {
        $this->assertNotNull($this->app);
        $original = new CacheManager($this->app);
        $original->extend('custom', function (): Repository {
            /** @var CacheManager $this */
            return $this->repository(new RefusingStore);
        });
        Config::set('cache.stores.custom', ['driver' => 'custom']);

        $manager = CacheerCacheManager::replacing($original, $this->app);

        $this->assertInstanceOf(RoutedStore::class, $manager->store('custom')->getStore());
    }

    public function test_doctor_lists_how_each_store_runs(): void
    {
        Config::set('cache.stores', [
            'files' => ['driver' => 'file', 'path' => $this->directory().'/doctor'],
            'memory' => ['driver' => 'array'],
        ]);

        $this->assertSame(0, Artisan::call('cacheer:doctor'));
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/files\s*\|\s*file\s*\|\s*routed/', $output);
        $this->assertMatchesRegularExpression('/memory\s*\|\s*array\s*\|\s*not routed: it lacks add\(\) or locks/', $output);
    }

    /**
     * Run the same calls against a cache, returning their results and the
     * Laravel cache events they fired.
     *
     * @return array{array<string, mixed>, list<string>}
     */
    private function scenario(Repository $cache): array
    {
        $cache->flush();
        $events = [];
        Event::listen('Illuminate\Cache\Events\*', function (string $event) use (&$events): void {
            $events[] = $event;
        });
        $long = str_repeat('k', 1100);

        $results = [
            'miss' => $cache->get('a', 'default'),
            'put' => $cache->put('a', ['v' => 1], 60),
            'hit' => $cache->get('a'),
            'has' => $cache->has('a'),
            'many' => $cache->many(['a', 'missing']),
            'putMany' => $cache->putMany([1 => 'one', 'b' => 'bee'], 60),
            'many numeric' => $cache->many([1, 'b']),
            'add new' => $cache->add('c', 'sea', 60),
            'add existing' => $cache->add('c', 'other', 60),
            'increment missing' => $cache->increment('counter'),
            'increment' => $cache->increment('counter', 4),
            'decrement' => $cache->decrement('counter'),
            'counter' => $cache->get('counter'),
            'forever' => $cache->forever('f', 'kept'),
            'remember' => $cache->remember('r', 60, fn (): string => 'computed'),
            'remember again' => $cache->remember('r', 60, fn (): string => 'recomputed'),
            'flexible' => $cache->flexible('fx', [5, 10], fn (): string => 'flexible'),
            'pull' => $cache->pull('c'),
            'cached null' => [$cache->put('n', null, 60), $cache->get('n', 'default')],
            'zero lifetime' => [$cache->put('z', 'gone', 0), $cache->get('z')],
            'empty key' => [$cache->put('', 'empty', 60), $cache->get('')],
            'long key' => [$cache->put($long, 'long', 60), $cache->get($long)],
            'forget' => $cache->forget('a'),
            'forget missing' => $cache->forget('a'),
            'flush' => $cache->flush(),
            'after flush' => $cache->get('f'),
        ];

        Event::forget('Illuminate\Cache\Events\*');

        return [$results, $events];
    }

    /**
     * Configure one of Laravel's own stores, with its backend prepared.
     *
     * @return string The store name.
     */
    private function nativeStore(string $driver): string
    {
        $name = 'native_'.$driver;

        if ($driver === 'database' && ! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration')->index();
            });
            Schema::create('cache_locks', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration')->index();
            });
        }

        if ($driver === 'redis') {
            $this->useRedis();
        }

        Config::set("cache.stores.{$name}", match ($driver) {
            'file' => ['driver' => 'file', 'path' => $this->directory().'/'.$name],
            'database' => ['driver' => 'database', 'table' => 'cache', 'lock_table' => 'cache_locks'],
            'redis' => ['driver' => 'redis', 'connection' => 'cacheer_test', 'lock_connection' => 'cacheer_test'],
            default => throw new RuntimeException("Unknown test driver [{$driver}]."),
        });

        return $name;
    }

    /**
     * A store from the application's cache manager, which routes it.
     */
    private function routed(string $name): Repository
    {
        $repository = $this->manager()->store($name);
        $this->assertInstanceOf(Repository::class, $repository);

        return $repository;
    }

    /**
     * The same store from a plain Laravel cache manager, which does not route it.
     */
    private function native(string $name): Repository
    {
        $this->assertNotNull($this->app);
        $repository = (new CacheManager($this->app))->store($name);
        $this->assertInstanceOf(Repository::class, $repository);

        return $repository;
    }

    /**
     * The application's cache manager, which routing replaced.
     */
    private function manager(): CacheerCacheManager
    {
        $manager = $this->app?->make('cache');
        $this->assertInstanceOf(CacheerCacheManager::class, $manager);

        return $manager;
    }
}

/**
 * A unique job, deduplicated through the default store.
 */
final class RoutedUniqueReport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;

    /**
     * Do nothing; only dispatching matters.
     */
    public function handle(): void {}
}
