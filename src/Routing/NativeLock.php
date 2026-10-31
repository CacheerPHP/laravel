<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Routing;

use Illuminate\Contracts\Cache\Lock as LaravelLock;
use Silviooosilva\CacheerPhp\Contracts\Lock;

/**
 * A CacheerPHP lock that is one of Laravel's locks.
 *
 * @internal
 */
final readonly class NativeLock implements Lock
{
    /** How long to wait between attempts, as Laravel's own locks do. */
    private const float RETRY_SECONDS = 0.25;

    /**
     * Wrap a Laravel lock.
     *
     * @param  LaravelLock  $lock  The lock, not yet acquired.
     */
    public function __construct(private LaravelLock $lock) {}

    /**
     * Take the lock if no one holds it.
     *
     * @return bool Whether the lock was taken.
     */
    public function acquire(): bool
    {
        return (bool) $this->lock->get();
    }

    /**
     * Wait for the lock. Laravel's own block() takes whole seconds, so this
     * polls the lock itself to keep a fractional wait exact.
     *
     * @param  float  $seconds  How long to wait at most.
     * @return bool Whether the lock was taken in time.
     */
    public function block(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        while (! $this->acquire()) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                return false;
            }

            usleep((int) (min($remaining, self::RETRY_SECONDS) * 1_000_000));
        }

        return true;
    }

    /**
     * Release the lock if this owner holds it.
     *
     * @return bool Whether the lock was released.
     */
    public function release(): bool
    {
        return (bool) $this->lock->release();
    }
}
