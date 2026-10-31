<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Routing;

use BadMethodCallException;

/**
 * A routed store whose native store supports Laravel's tags, such as Memcached.
 * Tagged operations run on the native store's own tagged cache.
 */
class TaggableRoutedStore extends RoutedStore
{
    /**
     * Begin a tagged cache on the native store.
     *
     * @param  array<array-key, mixed>|mixed  $names  The tag names.
     * @return mixed The native store's tagged cache.
     */
    public function tags($names): mixed
    {
        if (! method_exists($this->native, 'tags')) {
            throw new BadMethodCallException('This cache store does not support tagging.');
        }

        return $this->native->tags($names);
    }
}
