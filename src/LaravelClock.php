<?php

declare(strict_types=1);

namespace Cacheer\Laravel;

use Illuminate\Support\Carbon;
use Silviooosilva\CacheerPhp\Contracts\Clock;

/**
 * Expiry follows Laravel's clock, so `$this->travel(...)` and
 * `Carbon::setTestNow()` expire CacheerPHP entries the way they expire entries
 * in Laravel's own stores.
 *
 * Fractional time and sleeping stay on the real clock. CacheerPHP uses them only
 * for lock deadlines and check intervals, and a frozen test clock must not turn
 * a contended lock wait into an endless loop.
 */
final class LaravelClock implements Clock
{
    /**
     * The current time.
     *
     * @return int Unix seconds, on Laravel's test clock when one is set.
     */
    public function now(): int
    {
        return Carbon::hasTestNow() ? Carbon::now()->getTimestamp() : time();
    }

    /**
     * The current time, with microseconds.
     *
     * @return float Unix seconds, always on the real clock.
     */
    public function nowFloat(): float
    {
        return microtime(true);
    }

    /**
     * Pause on the real clock.
     *
     * @param  int  $microseconds  How long to pause.
     */
    public function sleep(int $microseconds): void
    {
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }
}
