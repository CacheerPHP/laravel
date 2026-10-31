<?php

declare(strict_types=1);

namespace Cacheer\Laravel;

use Closure;
use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\InspectableStore;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Contracts\PrunableStore;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Contracts\TaggableStore;
use Silviooosilva\CacheerPhp\Contracts\TouchStore;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Stores\RedisStore;

/**
 * Connect inside an operation, where ResilientStore can catch an outage.
 * Failed construction is never cached, so the next attempt can recover.
 *
 * @internal
 */
final class DeferredStore implements AtomicStore, BatchStore, FlushableScopeStore, InspectableStore, LockingStore, PrunableStore, Store, TaggableStore, TouchStore
{
    use ForwardsToStore;

    private DatabaseStore|FileStore|RedisStore|null $store = null;

    private ?object $connection = null;

    /**
     * Create a store that builds its backend on first use.
     *
     * @param  Closure(): (DatabaseStore|FileStore|RedisStore)  $factory  Builds the backend.
     * @param  (Closure(): object)|null  $connect  Resolves the connection before every
     *                                             operation. When it gives another object
     *                                             than the store was built on, as after a
     *                                             reconnect, the store is built again.
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly ?Closure $connect = null,
    ) {}

    /**
     * The backend, built now if it was not yet or its connection changed.
     *
     * @return DatabaseStore|FileStore|RedisStore The backend.
     */
    private function store(): DatabaseStore|FileStore|RedisStore
    {
        if ($this->connect !== null) {
            $connection = ($this->connect)();

            if ($connection !== $this->connection) {
                $this->store = null;
                $this->connection = $connection;
            }
        }

        return $this->store ??= ($this->factory)();
    }
}
