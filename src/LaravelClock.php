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
    public function now(): int
    {
        return Carbon::hasTestNow() ? Carbon::now()->getTimestamp() : time();
    }

    public function nowFloat(): float
    {
        return microtime(true);
    }

    public function sleep(int $microseconds): void
    {
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }
}
