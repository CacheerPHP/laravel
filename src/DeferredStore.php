<?php

declare(strict_types=1);

namespace Cacheer\Laravel;

use Closure;
use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\InspectableStore;
use Silviooosilva\CacheerPhp\Contracts\Lock;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Contracts\PrunableStore;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Contracts\TaggableStore;
use Silviooosilva\CacheerPhp\Contracts\TouchStore;
use Silviooosilva\CacheerPhp\Kernel\CacheEntry;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Stores\RedisStore;

/**
 * Connect inside an operation, where ResilientStore can catch an outage.
 * Failed construction is never cached, so the next attempt can recover.
 * These three backends share every capability forwarded here.
 *
 * @internal
 */
final class DeferredStore implements AtomicStore, BatchStore, FlushableScopeStore, InspectableStore, LockingStore, PrunableStore, Store, TaggableStore, TouchStore
{
    private DatabaseStore|FileStore|RedisStore|null $store = null;

    /**
     * @param  Closure(): (DatabaseStore|FileStore|RedisStore)  $factory
     */
    public function __construct(private readonly Closure $factory) {}

    public function get(Key $key): CacheEntry
    {
        return $this->store()->get($key);
    }

    public function set(Key $key, mixed $value, Ttl $ttl): void
    {
        $this->store()->set($key, $value, $ttl);
    }

    public function delete(Key $key): bool
    {
        return $this->store()->delete($key);
    }

    public function clear(): void
    {
        $this->store()->clear();
    }

    /**
     * @param  iterable<Key>  $keys
     * @return list<CacheEntry>
     */
    public function getMany(iterable $keys): array
    {
        return $this->store()->getMany($keys);
    }

    /**
     * @param  iterable<array{key: Key, value: mixed}>  $entries
     */
    public function setMany(iterable $entries, Ttl $ttl): void
    {
        $this->store()->setMany($entries, $ttl);
    }

    /**
     * @param  iterable<Key>  $keys
     */
    public function deleteMany(iterable $keys): bool
    {
        return $this->store()->deleteMany($keys);
    }

    public function increment(Key $key, int $amount = 1, ?int $initial = null, ?Ttl $ttl = null): int
    {
        return $this->store()->increment($key, $amount, $initial, $ttl);
    }

    public function compareAndSwap(Key $key, mixed $expected, mixed $value, ?Ttl $ttl = null): bool
    {
        return $this->store()->compareAndSwap($key, $expected, $value, $ttl);
    }

    public function touch(Key $key, Ttl $ttl): bool
    {
        return $this->store()->touch($key, $ttl);
    }

    public function prune(): int
    {
        return $this->store()->prune();
    }

    /**
     * @return iterable<CacheEntry>
     */
    public function entries(?Scope $scope = null): iterable
    {
        return $this->store()->entries($scope);
    }

    public function clearScope(Scope $scope): void
    {
        $this->store()->clearScope($scope);
    }

    public function tag(Key $key, string ...$tags): void
    {
        $this->store()->tag($key, ...$tags);
    }

    public function clearTag(string $tag): int
    {
        return $this->store()->clearTag($tag);
    }

    public function lock(string $name, Ttl $ttl): Lock
    {
        return $this->store()->lock($name, $ttl);
    }

    private function store(): DatabaseStore|FileStore|RedisStore
    {
        return $this->store ??= ($this->factory)();
    }
}
