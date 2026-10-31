<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Routing;

use Illuminate\Contracts\Cache\CanFlushLocks;
use LogicException;

/**
 * Laravel's lock flushing, forwarded to a native store that supports it.
 *
 * @internal
 */
trait FlushesNativeLocks
{
    /**
     * Remove every lock the native store holds.
     *
     * @return bool Whether the native store flushed its locks.
     */
    public function flushLocks(): bool
    {
        return $this->lockFlusher()->flushLocks();
    }

    /**
     * Whether the native store keeps its locks apart from its values.
     *
     * @return bool The native store's answer.
     */
    public function hasSeparateLockStore(): bool
    {
        return $this->lockFlusher()->hasSeparateLockStore();
    }

    /**
     * The native store, as one that can flush locks.
     *
     * @return CanFlushLocks The native store.
     */
    private function lockFlusher(): CanFlushLocks
    {
        return $this->native instanceof CanFlushLocks
            ? $this->native
            : throw new LogicException('The native cache store cannot flush locks.');
    }
}
