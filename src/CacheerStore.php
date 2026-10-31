<?php

declare(strict_types=1);

namespace Cacheer\Laravel;

use Generator;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use InvalidArgumentException;
use Silviooosilva\CacheerPhp\Contracts\Cache;

/**
 * A Laravel cache store backed by a CacheerPHP cache.
 *
 * Laravel's Repository (everything behind `Cache::`) talks to this class, and
 * this class only translates: keys stay as given, Laravel's seconds become
 * CacheerPHP TTLs, and every guarantee of the cache underneath — scoped
 * flushing, failover, encryption, telemetry — applies unchanged.
 *
 * Tags are deliberately not offered. Laravel's tagged caches namespace keys by
 * their tag set, while CacheerPHP tags are invalidation sets; mapping one onto
 * the other would break the semantics of both. Use the CacheerPHP cache from
 * {@see self::cache()} when you need tag invalidation.
 */
final readonly class CacheerStore implements LockProvider, Store
{
    private Cache $locks;

    /**
     * Create a store over a CacheerPHP cache.
     *
     * @param  Cache  $cache  The cache values are read from and written to.
     * @param  Cache|null  $locks  The keyspace holding lock records: the primary
     *                             cache without fallback or L1, so the guard and the
     *                             owner record share it throughout an operation, and
     *                             outside the scope of $cache, so flushing values
     *                             leaves held locks held. Defaults to a scope of $cache.
     * @param  string  $prefix  Reported by {@see self::getPrefix()}; the isolation it
     *                          stands for is already the scope of $cache.
     */
    public function __construct(
        private Cache $cache,
        ?Cache $locks = null,
        private string $prefix = '',
    ) {
        $this->locks = $locks ?? $cache->in('laravel-locks');
    }

    /**
     * The CacheerPHP cache behind this store, for features `Cache::` cannot
     * express, such as tag invalidation or scoped sub-caches.
     *
     * @return Cache The cache values are read from and written to.
     */
    public function cache(): Cache
    {
        return $this->cache;
    }

    /**
     * Read a value.
     *
     * @param  string  $key  The cache key.
     * @return mixed The value, or null on a miss.
     */
    public function get($key): mixed
    {
        return $this->cache->get((string) $key);
    }

    /**
     * Read several values at once.
     *
     * @param  array<array-key, string>  $keys  The cache keys.
     * @return array<string, mixed> Each key's value, or null on a miss, in request order.
     */
    public function many(array $keys): array
    {
        return $this->cache->many(array_map(strval(...), $keys));
    }

    /**
     * Store a value for a number of seconds.
     *
     * @param  string  $key  The cache key.
     * @param  mixed  $value  The value to store.
     * @param  int  $seconds  The lifetime; zero or less forgets the entry.
     * @return bool Always true; failures throw.
     */
    public function put($key, $value, $seconds): bool
    {
        $seconds = (int) $seconds;

        // An entry asked to live for no time at all is already expired; Laravel's
        // Repository forgets it for the same reason.
        if ($seconds <= 0) {
            $this->cache->delete((string) $key);

            return true;
        }

        $this->cache->set((string) $key, $value, $seconds);

        return true;
    }

    /**
     * Store several values for a number of seconds.
     *
     * @param  array<array-key, mixed>  $values  The values, by key.
     * @param  int  $seconds  The lifetime; zero or less forgets the entries.
     * @return bool Always true; failures throw.
     */
    public function putMany(array $values, $seconds): bool
    {
        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            $this->cache->deleteMany(array_map(strval(...), array_keys($values)));

            return true;
        }

        $this->cache->setMany(self::stringKeys($values), $seconds);

        return true;
    }

    /**
     * Store only when absent — atomic across processes whenever the store can
     * lock. Laravel's Repository calls this instead of its own get-then-put.
     *
     * @param  string  $key  The cache key.
     * @param  mixed  $value  The value to store.
     * @param  int  $seconds  The lifetime; zero or less stores nothing.
     * @return bool Whether the value was stored.
     */
    public function add($key, $value, $seconds): bool
    {
        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            return false;
        }

        return $this->cache->add((string) $key, $value, $seconds);
    }

    /**
     * Increase a counter. A missing counter starts at zero, as it does on
     * Laravel's Redis store; the new key never expires and an existing key
     * keeps its lifetime.
     *
     * @param  string  $key  The cache key.
     * @param  int|float  $value  The amount, a whole number.
     * @return int The new value.
     */
    public function increment($key, $value = 1): int
    {
        return $this->cache->increment((string) $key, self::amount($value), 0);
    }

    /**
     * Decrease a counter, starting a missing one at zero.
     *
     * @param  string  $key  The cache key.
     * @param  int|float  $value  The amount, a whole number.
     * @return int The new value.
     */
    public function decrement($key, $value = 1): int
    {
        return $this->cache->decrement((string) $key, self::amount($value), 0);
    }

    /**
     * Store a value that never expires.
     *
     * @param  string  $key  The cache key.
     * @param  mixed  $value  The value to store.
     * @return bool Always true; failures throw.
     */
    public function forever($key, $value): bool
    {
        $this->cache->forever((string) $key, $value);

        return true;
    }

    /**
     * Extend a live entry's lifetime without rewriting it. An expired entry is
     * not revived.
     *
     * @param  string  $key  The cache key.
     * @param  int  $seconds  The new lifetime; zero or less forgets the entry.
     * @return bool Whether a live entry was extended.
     */
    public function touch($key, $seconds): bool
    {
        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            return $this->forget($key);
        }

        return $this->cache->touch((string) $key, $seconds);
    }

    /**
     * Remove a value.
     *
     * @param  string  $key  The cache key.
     * @return bool Whether an entry was removed.
     */
    public function forget($key): bool
    {
        return $this->cache->delete((string) $key);
    }

    /**
     * Clear this store's keyspace only. With a prefix configured, other
     * applications and stores sharing the backend keep their entries, and
     * matching is literal, so `app_1` never clears `app_10`. Held locks stay
     * held, unless the prefix is empty: then the whole backend is cleared.
     *
     * @return bool Always true; failures throw.
     */
    public function flush(): bool
    {
        $this->cache->clear();

        return true;
    }

    /**
     * The prefix this store was configured with.
     *
     * @return string The prefix, or an empty string.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Create a lock, not yet acquired.
     *
     * @param  string  $name  The lock name.
     * @param  int  $seconds  Zero holds the lock until it is released.
     * @param  string|null  $owner  The owner token, or null for a new one.
     * @return CacheerLock The lock.
     */
    public function lock($name, $seconds = 0, $owner = null): CacheerLock
    {
        return new CacheerLock($this->locks, (string) $name, (int) $seconds, $owner);
    }

    /**
     * Rebuild a lock held by $owner, typically in another process, so that
     * process's lock can be released here.
     *
     * @param  string  $name  The lock name.
     * @param  string  $owner  The owner token of the holder.
     * @return CacheerLock The lock, owned by $owner.
     */
    public function restoreLock($name, $owner): CacheerLock
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * PHP turns numeric array keys into integers, which CacheerPHP batches refuse.
     *
     * @param  array<array-key, mixed>  $values  The values, by key.
     * @return Generator<string, mixed> The same values, keyed by strings.
     */
    private static function stringKeys(array $values): Generator
    {
        foreach ($values as $key => $value) {
            yield (string) $key => $value;
        }
    }

    /**
     * CacheerPHP counters are integers; a fractional amount would be silently
     * truncated, so it is rejected instead.
     *
     * @param  mixed  $value  The amount Laravel passed.
     * @return int The amount as an integer.
     */
    private static function amount(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value && abs($value) <= PHP_INT_MAX) {
            return (int) $value;
        }

        throw new InvalidArgumentException('CacheerPHP counters change by whole numbers only.');
    }
}
