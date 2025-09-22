<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @author Sílvio Silva <https://github.com/silviooosilva>
 * @package Silviooosilva\CacheerPhp
 * @method static bool add(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600)
 * @method bool add(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600)
 * @method static bool appendCache(string $cacheKey, mixed $cacheData, string $namespace = '')
 * @method bool appendCache(string $cacheKey, mixed $cacheData, string $namespace = '')
 * @method static bool clearCache(string $cacheKey, string $namespace = '')
 * @method bool clearCache(string $cacheKey, string $namespace = '')
 * @method static bool decrement(string $cacheKey, int $amount = 1, string $namespace = '')
 * @method bool decrement(string $cacheKey, int $amount = 1, string $namespace = '')
 * @method static bool flushCache()
 * @method bool flushCache()
 * @method static bool forever(string $cacheKey, mixed $cacheData)
 * @method bool forever(string $cacheKey, mixed $cacheData)
 * @method static mixed getAndForget(string $cacheKey, string $namespace = '')
 * @method mixed getAndForget(string $cacheKey, string $namespace = '')
 * @method static CacheDataFormatter|mixed getAll(string $namespace = '')
 * @method CacheDataFormatter|mixed getAll(string $namespace = '')
 * @method static mixed getCache(string $cacheKey, string $namespace = '', int|string $ttl = 3600)
 * @method mixed getCache(string $cacheKey, string $namespace = '', int|string $ttl = 3600)
 * @method static array|CacheDataFormatter getMany(array $cacheKeys, string $namespace = '', int|string $ttl = 3600)
 * @method array|CacheDataFormatter getMany(array $cacheKeys, string $namespace = '', int|string $ttl = 3600)
 * @method static getOptions(): array
 * @method getOptions(): array
 * @method static bool has(string $cacheKey, string $namespace = '')
 * @method bool has(string $cacheKey, string $namespace = '')
 * @method static bool increment(string $cacheKey, int $amount = 1, string $namespace = '')
 * @method bool increment(string $cacheKey, int $amount = 1, string $namespace = '')
 * @method static bool putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600)
 * @method bool putCache(string $cacheKey, mixed $cacheData, string $namespace = '', int|string $ttl = 3600)
 * @method static bool putMany(array $items, string $namespace = '', int $batchSize = 100)
 * @method bool putMany(array $items, string $namespace = '', int $batchSize = 100)
 * @method static bool tag(string $tag, string ...$keys)
 * @method bool tag(string $tag, string ...$keys)
 * @method static bool flushTag(string $tag)
 * @method bool flushTag(string $tag)
 * @method static mixed remember(string $cacheKey, int|string $ttl, Closure $callback)
 * @method mixed remember(string $cacheKey, int|string $ttl, Closure $callback)
 * @method static mixed rememberForever(string $cacheKey, Closure $callback)
 * @method mixed rememberForever(string $cacheKey, Closure $callback)
 * @method static bool renewCache(string $cacheKey, int|string $ttl = 3600, string $namespace = '')
 * @method bool renewCache(string $cacheKey, int|string $ttl = 3600, string $namespace = '')
 * @method static setConfig(): CacheConfig
 * @method setConfig(): CacheConfig
 * @method static setDriver(): CacheDriver
 * @method setDriver(): CacheDriver
 * @method static setUp(array $options): void
 * @method setUp(array $options): void
 * @see \Silviooosilva\CacheerPhp\Cacheer
 */
class Cacheer extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'cacheer';
    }
}
