<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Routing;

use Illuminate\Contracts\Cache\CanFlushLocks;

/**
 * A routed store whose native store supports Laravel's tags and can flush its
 * locks, such as Redis.
 */
final class TaggableLockFlushingRoutedStore extends TaggableRoutedStore implements CanFlushLocks
{
    use FlushesNativeLocks;
}
