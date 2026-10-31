<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Routing;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store as NativeStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\CapabilityAware;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedCapabilityException;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Ttl;

/**
 * A CacheerPHP store over one of Laravel's stores, in that store's own format.
 *
 * Values are written under the same keys and read back as the native store
 * returns them, so CacheerPHP and Laravel share every entry. Native stores do
 * not record when an entry was written or return its expiry, and Laravel treats
 * a cached null as a miss: entries have an unknown age, no reported expiry, and
 * a null reads as a miss.
 *
 * Only batches and locks are offered. Counters, touch, scoped clears, tags, and
 * inspection differ between Laravel's stores or do not exist there, and are
 * never emulated.
 *
 * @internal
 */
final readonly class LaravelStore implements BatchStore, CapabilityAware, LockingStore, Store
{
    /**
     * Wrap a Laravel store.
     *
     * @param  NativeStore  $native  The Laravel store.
     */
    public function __construct(private NativeStore $native) {}

    /**
     * Whether this store offers a capability.
     *
     * @param  string  $capability  A CacheerPHP store interface.
     * @return bool Whether it is offered.
     */
    public function supports(string $capability): bool
    {
        return match ($capability) {
            Store::class, BatchStore::class => true,
            LockingStore::class => $this->native instanceof LockProvider,
            default => false,
        };
    }

    /**
     * Read an entry.
     *
     * @param  Key  $key  The key.
     * @return CacheEntry The entry, of unknown age, or a miss.
     */
    public function get(Key $key): CacheEntry
    {
        return self::entry($key, $this->native->get(self::name($key)));
    }

    /**
     * Write an entry.
     *
     * @param  Key  $key  The key.
     * @param  mixed  $value  The value.
     * @param  Ttl  $ttl  Its lifetime.
     */
    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $name = self::name($key);
        $seconds = $ttl->inSeconds();

        $stored = $seconds === null
            ? $this->native->forever($name, $value)
            : $this->native->put($name, $value, $seconds);

        if (! $stored) {
            throw NativeOperationRefused::for($seconds === null ? 'forever' : 'put', $name);
        }
    }

    /**
     * Remove an entry.
     *
     * @param  Key  $key  The key.
     * @return bool Whether the native store removed it.
     */
    public function delete(Key $key): bool
    {
        // A custom store may return something other than a boolean.
        return (bool) $this->native->forget(self::name($key));
    }

    /**
     * Remove every entry of the native store.
     */
    public function clear(): void
    {
        if (! $this->native->flush()) {
            throw NativeOperationRefused::for('flush');
        }
    }

    /**
     * Read several entries.
     *
     * @param  iterable<Key>  $keys  The keys.
     * @return list<CacheEntry> One entry per key, misses included, in order.
     */
    public function getMany(iterable $keys): array
    {
        $keys = [...$keys];

        if ($keys === []) {
            return [];
        }

        $values = $this->native->many(array_map(self::name(...), $keys));
        $entries = [];

        foreach ($keys as $key) {
            $entries[] = self::entry($key, $values[self::name($key)] ?? null);
        }

        return $entries;
    }

    /**
     * Write several entries with one lifetime.
     *
     * @param  iterable<array{key: Key, value: mixed}>  $entries  The entries.
     * @param  Ttl  $ttl  Their lifetime.
     */
    public function setMany(iterable $entries, Ttl $ttl): void
    {
        $values = [];

        foreach ($entries as $entry) {
            $values[self::name($entry['key'])] = $entry['value'];
        }

        $seconds = $ttl->inSeconds();

        if ($values === []) {
            return;
        }

        if ($seconds !== null) {
            if (! $this->native->putMany($values, $seconds)) {
                throw NativeOperationRefused::for('putMany');
            }

            return;
        }

        foreach ($values as $name => $value) {
            if (! $this->native->forever((string) $name, $value)) {
                throw NativeOperationRefused::for('forever', (string) $name);
            }
        }
    }

    /**
     * Remove several entries.
     *
     * @param  iterable<Key>  $keys  The keys.
     * @return bool Whether the native store removed every one.
     */
    public function deleteMany(iterable $keys): bool
    {
        $deleted = true;

        foreach ($keys as $key) {
            $deleted = (bool) $this->native->forget(self::name($key)) && $deleted;
        }

        return $deleted;
    }

    /**
     * Create one of the native store's locks.
     *
     * @param  string  $name  The lock name.
     * @param  Ttl  $ttl  How long the lock is held at most.
     * @return Lock The lock, not yet acquired.
     */
    public function lock(string $name, Ttl $ttl): Lock
    {
        if (! $this->native instanceof LockProvider) {
            throw UnsupportedCapabilityException::for(LockingStore::class, 'lock');
        }

        return new NativeLock($this->native->lock($name, $ttl->inSeconds() ?? 0));
    }

    /**
     * The native key for a CacheerPHP key: the key itself at the root scope.
     *
     * @param  Key  $key  The key.
     * @return string The native key.
     */
    private static function name(Key $key): string
    {
        return (string) $key;
    }

    /**
     * An entry for a native value.
     *
     * @param  Key  $key  The key.
     * @param  mixed  $value  The value the native store returned.
     * @return CacheEntry A hit of unknown age, or a miss for null.
     */
    private static function entry(Key $key, mixed $value): CacheEntry
    {
        return $value === null ? CacheEntry::miss($key) : CacheEntry::hit($key, $value, null, null);
    }
}
