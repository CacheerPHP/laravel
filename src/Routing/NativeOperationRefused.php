<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Routing;

use RuntimeException;
use Silviooosilva\CacheerPhp\Exceptions\CacheException;

/**
 * A Laravel store returned false where CacheerPHP expects a write to succeed.
 *
 * It is a CacheException, so the engine and failover pass it on unchanged
 * instead of treating it as an outage, and {@see RoutedStore} turns it back
 * into the false the native store returned.
 *
 * @internal
 */
final class NativeOperationRefused extends RuntimeException implements CacheException
{
    /**
     * Describe the refused operation.
     *
     * @param  string  $operation  The native method that returned false.
     * @param  string|null  $key  The key it was called with, or null for none.
     * @return self The exception.
     */
    public static function for(string $operation, ?string $key = null): self
    {
        return new self($key === null
            ? "The Laravel store refused {$operation}()."
            : "The Laravel store refused {$operation}() for key [{$key}].");
    }
}
