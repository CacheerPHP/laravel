<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests\Feature;

use BadMethodCallException;
use Cacheer\Laravel\CacheerStore;
use Cacheer\Laravel\Tests\TestCase;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Silviooosilva\CacheerPhp\Exceptions\CacheException;

/**
 * Laravel's `Cache::` API on a cacheer store, run against every backend.
 */
final class CacheerStoreTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_values_round_trip_and_misses_return_the_default(array $backend): void
    {
        $cache = $this->store($backend);

        $this->assertTrue($cache->put('user:1', ['name' => 'Ada'], 60));
        $this->assertSame(['name' => 'Ada'], $cache->get('user:1'));
        $this->assertTrue($cache->has('user:1'));
        $this->assertSame('fallback', $cache->get('user:2', 'fallback'));
        $this->assertTrue($cache->missing('user:2'));
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_batches_keep_request_order_and_report_misses_as_null(array $backend): void
    {
        $cache = $this->store($backend);

        $this->assertTrue($cache->putMany(['b' => 2, 'a' => 1], 60));

        $this->assertSame(['a' => 1, 'missing' => null, 'b' => 2], $cache->many(['a', 'missing', 'b']));
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_batches_accept_numeric_keys(array $backend): void
    {
        $cache = $this->store($backend);

        $this->assertTrue($cache->putMany([1 => 'one', '20' => 'twenty'], 60));
        $this->assertTrue($cache->setMultiple([300 => 'three hundred'], 60));

        $this->assertSame([1 => 'one', 20 => 'twenty', 300 => 'three hundred'], $cache->many([1, 20, 300]));
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_entries_expire_on_laravels_clock(array $backend): void
    {
        $cache = $this->store($backend);
        $cache->put('short', 'value', 60);
        $cache->forever('long', 'value');

        $this->travel(59)->seconds();
        $this->assertSame('value', $cache->get('short'));

        $this->travel(2)->seconds();
        $this->assertNull($cache->get('short'));

        $this->travel(10)->years();
        $this->assertSame('value', $cache->get('long'));
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_a_zero_or_negative_lifetime_forgets_the_entry(array $backend): void
    {
        $cache = $this->store($backend);
        $store = $this->cacheerStore($cache);
        $cache->forever('a', 1);
        $cache->forever('b', 2);

        $this->assertTrue($store->put('a', 'new', 0));
        $this->assertTrue($store->putMany(['b' => 'new'], -5));

        $this->assertSame(['a' => null, 'b' => null], $cache->many(['a', 'b']));
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_add_stores_only_when_absent(array $backend): void
    {
        $cache = $this->store($backend);

        $this->assertTrue($cache->add('key', 'first', 60));
        $this->assertFalse($cache->add('key', 'second', 60));
        $this->assertSame('first', $cache->get('key'));

        $this->travel(61)->seconds();
        $this->assertTrue($cache->add('key', 'third', 60));
        $this->assertSame('third', $cache->get('key'));
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_counters_start_at_zero_and_keep_their_lifetime(array $backend): void
    {
        $cache = $this->store($backend);

        $this->assertSame(1, $cache->increment('visits'));
        $this->assertSame(6, $cache->increment('visits', 5));
        $this->assertSame(4, $cache->decrement('visits', 2));
        $this->assertSame(-1, $cache->decrement('fresh'));

        $cache->put('limited', 10, 60);
        $this->assertSame(11, $cache->increment('limited'));
        $this->travel(61)->seconds();
        $this->assertNull($cache->get('limited'));
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_counters_refuse_fractions_and_non_integer_values(array $backend): void
    {
        $cache = $this->store($backend);
        $cache->forever('name', 'Ada');

        try {
            $cache->increment('name');
            $this->fail('Incrementing a string must fail.');
        } catch (CacheException) {
            $this->assertSame('Ada', $cache->get('name'));
        }

        $this->expectException(InvalidArgumentException::class);
        $cache->increment('visits', 1.5);
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_touch_extends_a_live_entry_but_never_revives_an_expired_one(array $backend): void
    {
        $cache = $this->store($backend);
        $store = $this->cacheerStore($cache);
        $cache->put('live', 'value', 60);
        $cache->put('gone', 'value', 10);

        $this->travel(30)->seconds();
        $this->assertTrue($store->touch('live', 120));
        $this->assertFalse($store->touch('gone', 120));

        $this->travel(100)->seconds();
        $this->assertSame('value', $cache->get('live'));
        $this->assertNull($cache->get('gone'));
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_forget_reports_whether_it_removed_something(array $backend): void
    {
        $cache = $this->store($backend);
        $cache->put('key', 'value', 60);

        $this->assertTrue($cache->forget('key'));
        $this->assertNull($cache->get('key'));
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_remember_computes_once(array $backend): void
    {
        $cache = $this->store($backend);
        $counter = new class
        {
            public int $calls = 0;
        };
        $compute = fn (): string => 'computed '.++$counter->calls;

        $cache->remember('key', 60, $compute);
        $cache->remember('key', 60, $compute);
        $cache->rememberForever('other', $compute);

        $this->assertSame(2, $counter->calls);
        $this->assertSame('computed 1', $cache->get('key'));
        $this->assertSame('computed 2', $cache->get('other'));
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_flush_clears_only_its_own_prefix_matched_literally(array $backend): void
    {
        $shared = $backend;

        if (($backend['backend'] ?? null) === 'file') {
            $shared['path'] = $this->directory().'/shared';
        }

        $one = $this->store($shared + ['prefix' => 'app_1']);
        $ten = $this->store($shared + ['prefix' => 'app_10']);
        $one->forever('key', 'one');
        $ten->forever('key', 'ten');

        $this->assertTrue($one->flush());

        $this->assertNull($one->get('key'));
        $this->assertSame('ten', $ten->get('key'));
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_flushing_the_cache_leaves_held_locks_held(array $backend): void
    {
        $cache = $this->store($backend);
        $held = $this->cacheerStore($cache)->lock('deploy', 600);
        $this->assertTrue($held->get());

        $this->assertTrue($cache->flush());

        $this->assertFalse($this->cacheerStore($cache)->lock('deploy', 600)->get());
        $this->assertTrue($held->release());
    }

    public function test_tags_are_not_offered(): void
    {
        $this->expectException(BadMethodCallException::class);

        $this->store(['backend' => 'array'])->tags(['users'])->put('key', 'value', 60);
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_a_lock_has_one_owner_at_a_time(array $backend): void
    {
        $cache = $this->store($backend);
        $first = $this->cacheerStore($cache)->lock('report', 60);
        $second = $this->cacheerStore($cache)->lock('report', 60);

        $this->assertTrue($first->get());
        $this->assertFalse($second->get());
        $this->assertFalse($second->release());

        $this->assertTrue($first->release());
        $this->assertTrue($second->get());
        $this->assertTrue($second->release());
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_a_lock_runs_a_callback_and_releases_afterwards(array $backend): void
    {
        $cache = $this->store($backend);

        $this->assertSame('done', $this->cacheerStore($cache)->lock('job', 60)->get(fn (): string => 'done'));
        $this->assertTrue($this->cacheerStore($cache)->lock('job', 60)->get());
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_a_lock_can_be_released_by_its_owner_from_another_process(array $backend): void
    {
        $cache = $this->store($backend);
        $lock = $this->cacheerStore($cache)->lock('import', 60);
        $lock->get();

        $this->assertFalse($this->cacheerStore($cache)->restoreLock('import', 'someone-else')->release());
        $this->assertTrue($this->cacheerStore($cache)->restoreLock('import', $lock->owner())->release());
        $this->assertTrue($this->cacheerStore($cache)->lock('import', 60)->get());
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_force_release_frees_a_lock_whatever_its_owner(array $backend): void
    {
        $cache = $this->store($backend);
        $this->cacheerStore($cache)->lock('mutex')->get();

        $this->cacheerStore($cache)->lock('mutex')->forceRelease();

        $this->assertTrue($this->cacheerStore($cache)->lock('mutex')->get());
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_an_expired_holder_cannot_release_the_next_holders_lock(array $backend): void
    {
        $cache = $this->store($backend);
        $stale = $this->cacheerStore($cache)->lock('sync', 10);
        $stale->get();

        $this->travel(11)->seconds();
        $current = $this->cacheerStore($cache)->lock('sync', 10);

        $this->assertTrue($current->get());
        $this->assertFalse($stale->release());
        $this->assertTrue($current->isOwnedByCurrentProcess());
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_a_lock_without_lifetime_is_held_until_released(array $backend): void
    {
        $cache = $this->store($backend);
        $this->cacheerStore($cache)->lock('forever')->get();

        $this->travel(10)->years();

        $this->assertFalse($this->cacheerStore($cache)->lock('forever')->get());
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_only_the_owner_can_refresh_a_lock(array $backend): void
    {
        $cache = $this->store($backend);
        $lock = $this->cacheerStore($cache)->lock('lease', 10);
        $lock->get();

        $this->travel(8)->seconds();
        $this->assertTrue($lock->refresh());
        $this->assertFalse($this->cacheerStore($cache)->restoreLock('lease', 'someone-else')->refresh());

        $this->travel(8)->seconds();
        $this->assertFalse($this->cacheerStore($cache)->lock('lease', 10)->get());
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_refreshing_a_restored_lock_keeps_its_lifetime(array $backend): void
    {
        $cache = $this->store($backend);
        $lock = $this->cacheerStore($cache)->lock('lease', 10);
        $lock->get();

        $this->assertTrue($this->cacheerStore($cache)->restoreLock('lease', (string) $lock->owner())->refresh());

        $this->travel(11)->seconds();
        $this->assertTrue($this->cacheerStore($cache)->lock('lease', 10)->get());
    }

    public function test_blocking_on_a_held_lock_times_out(): void
    {
        $cache = $this->store(['backend' => 'array']);
        $this->cacheerStore($cache)->lock('busy', 60)->get();

        $this->expectException(LockTimeoutException::class);

        $this->cacheerStore($cache)->lock('busy', 60)->betweenBlockedAttemptsSleepFor(10)->block(0);
    }

    public function test_unique_jobs_are_deduplicated_through_the_store(): void
    {
        Config::set('cache.default', 'unique');
        $this->store(['backend' => 'array'], 'unique');
        Queue::fake();

        UniqueReport::dispatch();
        UniqueReport::dispatch();

        Queue::assertPushed(UniqueReport::class, 1);
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    #[DataProvider('backends')]
    public function test_rate_limits_are_counted_and_reset_through_the_store(array $backend): void
    {
        Config::set('cache.default', 'limits');
        $this->store($backend, 'limits');
        $limiter = $this->app?->make(RateLimiter::class);
        $this->assertInstanceOf(RateLimiter::class, $limiter);

        $this->assertTrue($limiter->attempt('login:ada', 2, fn (): bool => true, 60));
        $this->assertTrue($limiter->attempt('login:ada', 2, fn (): bool => true, 60));
        $this->assertFalse($limiter->attempt('login:ada', 2, fn (): bool => true, 60));
        $this->assertTrue($limiter->tooManyAttempts('login:ada', 2));

        $this->travel(61)->seconds();
        $this->assertFalse($limiter->tooManyAttempts('login:ada', 2));
    }

    public function test_the_underlying_cacheer_cache_is_scoped_by_the_prefix(): void
    {
        $cache = $this->store(['backend' => 'array', 'prefix' => 'shop']);
        $cache->forever('product', 'boots');

        $cacheer = $this->cacheerStore($cache)->cache();

        $this->assertSame('shop', (string) $cacheer->boundScope());
        $this->assertSame('boots', $cacheer->get('product'));
        $this->assertSame('shop', $cache->getPrefix());
    }

    /**
     * The cacheer store behind a repository.
     *
     * @param  Repository  $cache  The repository.
     * @return CacheerStore The store.
     */
    private function cacheerStore(Repository $cache): CacheerStore
    {
        $store = $cache->getStore();
        $this->assertInstanceOf(CacheerStore::class, $store);

        return $store;
    }
}

/**
 * A unique job for the deduplication test.
 */
final class UniqueReport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;

    /**
     * Do nothing; only dispatching matters.
     */
    public function handle(): void {}
}
