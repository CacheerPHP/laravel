<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Routing;

use Cacheer\Laravel\CacheerStore;
use Cacheer\Laravel\LaravelClock;
use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Cache\FailoverStore;
use Illuminate\Cache\MemoizedStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\CanFlushLocks;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Foundation\Application;
use ReflectionFunction;
use Silviooosilva\CacheerPhp\Contracts\Clock;

/**
 * Laravel's cache manager, routing each store it builds through CacheerPHP.
 *
 * Every store, built-in or custom, becomes a repository in {@see self::repository()},
 * so that is where it is wrapped in a {@see RoutedStore}. A store the router
 * cannot mirror exactly is left alone, and the reason is kept for `cacheer:doctor`.
 */
final class CacheerCacheManager extends CacheManager
{
    /** The status of a store that runs through CacheerPHP. */
    public const string ROUTED = 'routed';

    /** @var array<string, string> */
    private array $routing = [];

    /**
     * Take over from Laravel's cache manager, keeping its stores and custom
     * drivers. Another package's manager subclass is left in place.
     *
     * @param  mixed  $manager  What the container built for `cache`.
     * @param  Application  $app  The application.
     * @return mixed The manager to use: a replacement only for Laravel's own.
     */
    public static function replacing(mixed $manager, Application $app): mixed
    {
        if (! is_object($manager) || $manager::class !== CacheManager::class) {
            return $manager;
        }

        $replacement = new self($app);
        $replacement->stores = $manager->stores;

        foreach ($manager->customCreators as $driver => $creator) {
            $replacement->customCreators[$driver] = $replacement->rebound($creator);
        }

        return $replacement;
    }

    /**
     * Create a repository, routing its store through CacheerPHP when possible.
     *
     * @param  Store  $store  The store.
     * @param  array<string, mixed>  $config  The store's configuration.
     * @return Repository The repository.
     */
    #[\Override]
    public function repository(Store $store, array $config = [])
    {
        return parent::repository($this->route($store, $config), $config);
    }

    /**
     * How each store built so far runs.
     *
     * @return array<string, string> {@see self::ROUTED}, or why the store is not routed, by store name.
     */
    public function routing(): array
    {
        return $this->routing;
    }

    /**
     * Wrap a store, or leave it alone and record why.
     *
     * @param  Store  $store  The store.
     * @param  array<string, mixed>  $config  The store's configuration.
     * @return Store The store to put in the repository.
     */
    private function route(Store $store, array $config): Store
    {
        if ($store instanceof RoutedStore) {
            return $store;
        }

        $reason = $this->exclusion($store, $config);
        $name = $config['store'] ?? null;

        if (is_string($name)) {
            $this->routing[$name] = $reason ?? self::ROUTED;
        }

        if ($reason !== null || ! $store instanceof LockProvider) {
            return $store;
        }

        $clock = $this->clock();
        $taggable = method_exists($store, 'tags');

        return match (true) {
            $taggable && $store instanceof CanFlushLocks => new TaggableLockFlushingRoutedStore($store, $clock),
            $taggable => new TaggableRoutedStore($store, $clock),
            $store instanceof CanFlushLocks => new LockFlushingRoutedStore($store, $clock),
            default => new RoutedStore($store, $clock),
        };
    }

    /**
     * Why a store cannot be routed, if it cannot.
     *
     * @param  Store  $store  The store.
     * @param  array<string, mixed>  $config  The store's configuration.
     * @return string|null The reason, or null to route it.
     */
    private function exclusion(Store $store, array $config): ?string
    {
        if ($this->app->make('config')->get('cache.cacheer.route', true) === false) {
            return 'routing is turned off (cache.cacheer.route)';
        }

        if (($config['cacheer'] ?? null) === false) {
            return "turned off in the store's configuration";
        }

        return match (true) {
            $store instanceof CacheerStore => 'the cacheer driver already runs on CacheerPHP',
            $store instanceof MemoizedStore, $store instanceof FailoverStore => 'it wraps other stores, which are routed themselves',
            $store instanceof NullStore => 'it stores nothing',
            $store instanceof DynamoDbStore => "Laravel's scheduler and command mutexes need to see a DynamoDbStore",
            $store instanceof RedisStore && $this->serializesRedis($config) => "Laravel's rate limiter needs to see a RedisStore to bypass phpredis serialization",
            ! $store instanceof LockProvider || ! method_exists($store, 'add') => 'it lacks add() or locks, which routing must mirror exactly',
            default => null,
        };
    }

    /**
     * Whether a Redis store's phpredis connection serializes or compresses values.
     *
     * @param  array<string, mixed>  $config  The store's configuration.
     * @return bool Whether a serializer or compression is configured.
     */
    private function serializesRedis(array $config): bool
    {
        $redis = (array) $this->app->make('config')->get('database.redis', []);

        if (($redis['client'] ?? 'phpredis') !== 'phpredis') {
            return false;
        }

        $connection = (array) ($redis[$config['connection'] ?? 'default'] ?? []);

        foreach (['serializer', 'compression'] as $option) {
            $values = [$redis['options'][$option] ?? null, $connection[$option] ?? null, $connection['options'][$option] ?? null];

            foreach ($values as $value) {
                if (! in_array($value, [null, false, '', 0, '0', 'none'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The clock entries expire on: the container's, else Laravel's.
     *
     * @return Clock The clock.
     */
    private function clock(): Clock
    {
        return $this->app->bound(Clock::class) ? $this->app->make(Clock::class) : new LaravelClock;
    }

    /**
     * Bind a custom driver's closure to this manager, as Laravel does when one
     * is registered.
     *
     * @param  Closure  $creator  The driver's closure.
     * @return Closure The closure, bound to this manager.
     */
    private function rebound(Closure $creator): Closure
    {
        $function = new ReflectionFunction($creator);

        if (! $function->isAnonymous()) {
            return $creator;
        }

        return ($function->isStatic() ? $creator->bindTo(null, self::class) : $creator->bindTo($this, self::class)) ?? $creator;
    }
}
