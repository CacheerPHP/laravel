<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Routing;

use Illuminate\Contracts\Cache\CanFlushLocks;

/**
 * A routed store whose native store can flush its locks, such as the database
 * and file stores.
 */
final class LockFlushingRoutedStore extends RoutedStore implements CanFlushLocks
{
    use FlushesNativeLocks;
}
