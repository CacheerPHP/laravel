<?php

declare(strict_types=1);

namespace Cacheer\Laravel;

use Silviooosilva\CacheerPhp\Contracts\AtomicStore;
use Silviooosilva\CacheerPhp\Contracts\BatchStore;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\FlushableScopeStore;
use Silviooosilva\CacheerPhp\Contracts\InspectableStore;
use Silviooosilva\CacheerPhp\Contracts\LockingStore;
use Silviooosilva\CacheerPhp\Contracts\PrunableStore;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Contracts\TaggableStore;
use Silviooosilva\CacheerPhp\Contracts\TouchStore;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;

/**
 * Process memory that sweeps out expired entries at most once a minute.
 *
 * An ArrayStore drops an expired entry only when that key is read again, so in
 * a long-running worker (Octane, queues) the `l1` tier and an `array` fallback
 * would otherwise keep every key they ever held.
 *
 * @internal
 */
final class PruningArrayStore implements AtomicStore, BatchStore, FlushableScopeStore, InspectableStore, LockingStore, PrunableStore, Store, TaggableStore, TouchStore
{
    use ForwardsToStore;

    private const int PRUNE_INTERVAL_SECONDS = 60;

    private int $prunedAt;

    /**
     * Create a store over process memory.
     *
     * @param  ArrayStore  $entries  The entries.
     * @param  Clock  $clock  Decides when entries expire and when to sweep.
     */
    public function __construct(
        private readonly ArrayStore $entries,
        private readonly Clock $clock,
    ) {
        $this->prunedAt = $clock->now();
    }

    /**
     * The entries, swept first when the last sweep was a minute or more ago.
     *
     * @return ArrayStore The entries.
     */
    private function store(): ArrayStore
    {
        $now = $this->clock->now();

        // Either direction: a test clock travelling back must not postpone sweeping.
        if (abs($now - $this->prunedAt) >= self::PRUNE_INTERVAL_SECONDS) {
            $this->prunedAt = $now;
            $this->entries->prune();
        }

        return $this->entries;
    }
}
