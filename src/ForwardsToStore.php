<?php

declare(strict_types=1);

namespace Cacheer\Laravel;

use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Stores\RedisStore;

/**
 * Every CacheerPHP store capability, forwarded to {@see self::store()}. The
 * stores it may return all implement each one.
 *
 * @internal
 */
trait ForwardsToStore
{
    /**
     * Read an entry.
     *
     * @param  Key  $key  The key.
     * @return CacheEntry The entry, or a miss.
     */
    public function get(Key $key): CacheEntry
    {
        return $this->store()->get($key);
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
        $this->store()->set($key, $value, $ttl);
    }

    /**
     * Remove an entry.
     *
     * @param  Key  $key  The key.
     * @return bool Whether an entry was removed.
     */
    public function delete(Key $key): bool
    {
        return $this->store()->delete($key);
    }

    /**
     * Remove every entry.
     */
    public function clear(): void
    {
        $this->store()->clear();
    }

    /**
     * Read several entries.
     *
     * @param  iterable<Key>  $keys  The keys.
     * @return list<CacheEntry> One entry per key, misses included, in order.
     */
    public function getMany(iterable $keys): array
    {
        return $this->store()->getMany($keys);
    }

    /**
     * Write several entries with one lifetime.
     *
     * @param  iterable<array{key: Key, value: mixed}>  $entries  The entries.
     * @param  Ttl  $ttl  Their lifetime.
     */
    public function setMany(iterable $entries, Ttl $ttl): void
    {
        $this->store()->setMany($entries, $ttl);
    }

    /**
     * Remove several entries.
     *
     * @param  iterable<Key>  $keys  The keys.
     * @return bool Whether every entry was removed.
     */
    public function deleteMany(iterable $keys): bool
    {
        return $this->store()->deleteMany($keys);
    }

    /**
     * Add to an integer counter atomically.
     *
     * @param  Key  $key  The key.
     * @param  int  $amount  The amount to add.
     * @param  int|null  $initial  The value of a missing counter, or null for zero.
     * @param  Ttl|null  $ttl  The new lifetime, or null to keep the current one.
     * @return int The new value.
     */
    public function increment(Key $key, int $amount = 1, ?int $initial = null, ?Ttl $ttl = null): int
    {
        return $this->store()->increment($key, $amount, $initial, $ttl);
    }

    /**
     * Replace a value only if it still holds the expected one.
     *
     * @param  Key  $key  The key.
     * @param  mixed  $expected  The value the entry must hold.
     * @param  mixed  $value  The new value.
     * @param  Ttl|null  $ttl  The new lifetime, or null to keep the current one.
     * @return bool Whether the value was replaced.
     */
    public function compareAndSwap(Key $key, mixed $expected, mixed $value, ?Ttl $ttl = null): bool
    {
        return $this->store()->compareAndSwap($key, $expected, $value, $ttl);
    }

    /**
     * Give a live entry a new lifetime.
     *
     * @param  Key  $key  The key.
     * @param  Ttl  $ttl  The new lifetime.
     * @return bool Whether a live entry was extended.
     */
    public function touch(Key $key, Ttl $ttl): bool
    {
        return $this->store()->touch($key, $ttl);
    }

    /**
     * Remove expired entries.
     *
     * @return int How many entries were removed.
     */
    public function prune(): int
    {
        return $this->store()->prune();
    }

    /**
     * List live entries.
     *
     * @param  Scope|null  $scope  Only entries in this scope, or null for all.
     * @return iterable<CacheEntry> The entries.
     */
    public function entries(?Scope $scope = null): iterable
    {
        return $this->store()->entries($scope);
    }

    /**
     * Remove every entry in a scope.
     *
     * @param  Scope  $scope  The scope.
     */
    public function clearScope(Scope $scope): void
    {
        $this->store()->clearScope($scope);
    }

    /**
     * Attach tags to an entry.
     *
     * @param  Key  $key  The key.
     * @param  string  ...$tags  The tags.
     */
    public function tag(Key $key, string ...$tags): void
    {
        $this->store()->tag($key, ...$tags);
    }

    /**
     * Remove every entry with a tag.
     *
     * @param  string  $tag  The tag.
     * @return int How many entries were removed.
     */
    public function clearTag(string $tag): int
    {
        return $this->store()->clearTag($tag);
    }

    /**
     * Create a lock, not yet acquired.
     *
     * @param  string  $name  The lock name.
     * @param  Ttl  $ttl  How long the lock is held at most.
     * @return Lock The lock.
     */
    public function lock(string $name, Ttl $ttl): Lock
    {
        return $this->store()->lock($name, $ttl);
    }

    /**
     * The store every capability is forwarded to.
     *
     * @return ArrayStore|DatabaseStore|FileStore|RedisStore The store.
     */
    abstract private function store(): ArrayStore|DatabaseStore|FileStore|RedisStore;
}
