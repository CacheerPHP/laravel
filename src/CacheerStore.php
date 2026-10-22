<?php

declare(strict_types=1);

namespace Cacheer\Laravel;

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
     * @param  Cache  $cache  The cache values are read from and written to.
     * @param  Cache|null  $lockCache  Where lock records live, when that must differ
     *                                 from $cache: lock ownership is read on every
     *                                 release, so it must come from the shared store,
     *                                 never from a tiered cache's local copy.
     * @param  string  $prefix  Reported by {@see self::getPrefix()}; the isolation it
     *                          stands for is already the scope of $cache.
     */
    public function __construct(
        private Cache $cache,
        ?Cache $lockCache = null,
        private string $prefix = '',
    ) {
        $this->locks = ($lockCache ?? $cache)->in('laravel-locks');
    }

    /**
     * The CacheerPHP cache behind this store, for features `Cache::` cannot
     * express, such as tag invalidation or scoped sub-caches.
     */
    public function cache(): Cache
    {
        return $this->cache;
    }

    /**
     * @param  string  $key
     */
    public function get($key): mixed
    {
        return $this->cache->get((string) $key);
    }

    /**
     * @param  array<array-key, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        return $this->cache->many(array_map(strval(...), $keys));
    }

    /**
     * @param  string  $key
     * @param  int  $seconds
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
     * @param  array<array-key, mixed>  $values
     * @param  int  $seconds
     */
    public function putMany(array $values, $seconds): bool
    {
        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            $this->cache->deleteMany(array_map(strval(...), array_keys($values)));

            return true;
        }

        $this->cache->setMany($values, $seconds);

        return true;
    }

    /**
     * Store only when absent — atomic across processes whenever the store can
     * lock. Laravel's Repository calls this instead of its own get-then-put.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
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
     * A missing counter starts at zero, as it does on Laravel's Redis store; the
     * new key never expires and an existing key keeps its lifetime.
     *
     * @param  string  $key
     * @param  int|float  $value
     */
    public function increment($key, $value = 1): int
    {
        return $this->cache->increment((string) $key, self::amount($value), 0);
    }

    /**
     * @param  string  $key
     * @param  int|float  $value
     */
    public function decrement($key, $value = 1): int
    {
        return $this->cache->decrement((string) $key, self::amount($value), 0);
    }

    /**
     * @param  string  $key
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
     * @param  string  $key
     * @param  int  $seconds
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
     * @param  string  $key
     */
    public function forget($key): bool
    {
        return $this->cache->delete((string) $key);
    }

    /**
     * Clear this store's keyspace only. With a prefix configured, other
     * applications and stores sharing the backend keep their entries, and
     * matching is literal, so `app_1` never clears `app_10`.
     */
    public function flush(): bool
    {
        $this->cache->clear();

        return true;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * @param  string  $name
     * @param  int  $seconds  Zero holds the lock until it is released.
     * @param  string|null  $owner
     */
    public function lock($name, $seconds = 0, $owner = null): CacheerLock
    {
        return new CacheerLock($this->locks, (string) $name, (int) $seconds, $owner);
    }

    /**
     * Rebuild a lock held by $owner, typically in another process, so that
     * process's lock can be released here.
     *
     * @param  string  $name
     * @param  string  $owner
     */
    public function restoreLock($name, $owner): CacheerLock
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * CacheerPHP counters are integers; a fractional amount would be silently
     * truncated, so it is rejected instead.
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
