<?php

declare(strict_types=1);

namespace Cacheer\Laravel;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Silviooosilva\CacheerPhp\Contracts\Cache;

/**
 * Registers the `cacheer` cache driver, and binds CacheerPHP's {@see Cache}
 * contract to the cache behind the application's cacheer store.
 */
final class CacheerServiceProvider extends ServiceProvider
{
    /**
     * Register the `cacheer` driver and the CacheerPHP `Cache` binding.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(StoreFactory::class, fn (Application $app): StoreFactory => new StoreFactory($app));

        $this->callAfterResolving('cache', function (CacheManager $manager): void {
            // Not static: Laravel rebinds driver creators to the cache manager.
            $manager->extend('cacheer', function (Application $app, array $config) use ($manager) {
                /** @var array<string, mixed> $config */
                return $manager->repository($app->make(StoreFactory::class)->make($config), $config);
            });
        });

        $this->app->bind(Cache::class, function (Application $app): Cache {
            $store = $app->make('cache')->store(self::cacheerStore($app->make('config')))->getStore();

            if (! $store instanceof CacheerStore) {
                throw new RuntimeException('The cacheer cache store is not backed by CacheerPHP.');
            }

            return $store->cache();
        });
    }

    /**
     * Offer the database backend's migration for publishing.
     */
    public function boot(): void
    {
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'cacheer-migrations');
    }

    /**
     * The default cache store when it uses the cacheer driver, otherwise the
     * first store that does.
     *
     * @param  Config  $config  The application's configuration.
     * @return string The store name.
     */
    private static function cacheerStore(Config $config): string
    {
        $default = $config->get('cache.default');
        $stores = (array) $config->get('cache.stores', []);

        if (is_string($default) && (($stores[$default]['driver'] ?? null) === 'cacheer')) {
            return $default;
        }

        foreach ($stores as $name => $store) {
            if (is_array($store) && ($store['driver'] ?? null) === 'cacheer') {
                return (string) $name;
            }
        }

        throw new RuntimeException('No cache store in config/cache.php uses the cacheer driver.');
    }
}
