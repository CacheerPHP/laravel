<?php

declare(strict_types=1);

namespace Cacheer\Laravel;

use Illuminate\Cache\Lock;
use LogicException;
use Silviooosilva\CacheerPhp\Contracts\Cache;
use Silviooosilva\CacheerPhp\Exceptions\CacheException;
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Throwable;

/**
 * A Laravel atomic lock whose owner is stored as data in a CacheerPHP cache.
 *
 * Laravel needs more from a lock than CacheerPHP's own locks expose: an owner
 * token another process can release with (`restoreLock()`), and a forced release
 * (unique jobs, the scheduler's `withoutOverlapping()`, isolatable commands).
 * So the lock is a cache record holding the owner, and every check-and-write on
 * that record runs under a short CacheerPHP lock. That makes acquire and release
 * atomic on any store that can lock, and keeps release owner-scoped: an expired
 * holder can never delete the lock someone else acquired after it.
 *
 * The cache supplied here must address the primary, without fallback or L1.
 * Backend outages at any step fail closed; programming and payload errors surface.
 */
final class CacheerLock extends Lock
{
    private const float GUARD_WAIT_SECONDS = 5.0;

    private const int GUARD_TTL_SECONDS = 10;

    /**
     * @param  Cache  $locks  The keyspace holding lock records.
     * @param  string  $name
     * @param  int  $seconds  Zero holds the lock until it is released.
     * @param  string|null  $owner
     */
    public function __construct(private readonly Cache $locks, $name, $seconds, $owner = null)
    {
        parent::__construct($name, $seconds, $owner);
    }

    public function acquire(): bool
    {
        return $this->guarded(function (): bool {
            if ($this->locks->entry($this->name)->isHit()) {
                return false;
            }

            $this->write((int) $this->seconds);

            return true;
        });
    }

    public function release(): bool
    {
        return $this->guarded(fn (): bool => $this->isOwnedByCurrentProcess() && $this->locks->delete($this->name));
    }

    /**
     * Release regardless of owner — Laravel's escape hatch for locks whose owner
     * is gone, such as a unique job's lock released after the job ran.
     */
    public function forceRelease(): void
    {
        $this->guarded(fn (): bool => $this->locks->delete($this->name));
    }

    /**
     * Restart the lock's lifetime, if this owner still holds it.
     *
     * @param  int|null  $seconds  Defaults to the lifetime the lock was created with.
     */
    public function refresh($seconds = null): bool
    {
        $seconds = (int) ($seconds ?? $this->seconds);

        return $this->guarded(function () use ($seconds): bool {
            if (! $this->isOwnedByCurrentProcess()) {
                return false;
            }

            $this->write($seconds);

            return true;
        });
    }

    protected function getCurrentOwner(): ?string
    {
        try {
            $owner = $this->locks->get($this->name);
        } catch (Throwable $error) {
            self::rethrowUnlessOutage($error);

            return null;
        }

        return is_string($owner) ? $owner : null;
    }

    private function write(int $seconds): void
    {
        if ($seconds > 0) {
            $this->locks->set($this->name, $this->owner, $seconds);

            return;
        }

        $this->locks->forever($this->name, $this->owner);
    }

    /**
     * @param  callable(): bool  $operation
     */
    private function guarded(callable $operation): bool
    {
        try {
            $guard = $this->locks->lock('guard:'.$this->name, self::GUARD_TTL_SECONDS);

            if (! $guard->block(self::GUARD_WAIT_SECONDS)) {
                return false;
            }

            try {
                return $operation();
            } finally {
                try {
                    $guard->release();
                } catch (Throwable $error) {
                    self::rethrowUnlessOutage($error);
                }
            }
        } catch (Throwable $error) {
            self::rethrowUnlessOutage($error);

            return false;
        }
    }

    private static function rethrowUnlessOutage(Throwable $error): void
    {
        // Match core's outage boundary without depending on its internal helper.
        $cause = $error;

        while ($cause instanceof StoreOperationFailedException && $cause->getPrevious() !== null) {
            $cause = $cause->getPrevious();
        }

        if ($cause instanceof \Error || $cause instanceof LogicException || $cause instanceof CacheException) {
            throw $error;
        }
    }
}
