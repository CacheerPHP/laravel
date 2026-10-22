<?php

declare(strict_types=1);

namespace Cacheer\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Predis\ClientInterface;
use Redis;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\RedisConnection;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Storage\Encryption\Keyring;
use Silviooosilva\CacheerPhp\Storage\EnvelopeCodec;
use Silviooosilva\CacheerPhp\Stores\ArrayStore;
use Silviooosilva\CacheerPhp\Stores\DatabaseStore;
use Silviooosilva\CacheerPhp\Stores\FileStore;
use Silviooosilva\CacheerPhp\Stores\RedisStore;
use Silviooosilva\CacheerPhp\Stores\ResilientStore;
use Silviooosilva\CacheerPhp\Stores\Support\PhpRedisConnection;
use Silviooosilva\CacheerPhp\Stores\Support\PredisConnection;

/**
 * Builds a {@see CacheerStore} from a `config/cache.php` store entry.
 *
 *     'cacheer' => [
 *         'driver' => 'cacheer',
 *         'backend' => 'redis',              // array, file, database, or redis
 *         'connection' => 'cache',           // Laravel redis or database connection
 *         'fallback' => 'file',              // serve from here while redis is down
 *         'l1' => 60,                        // in-process copies, at most 60 s old
 *     ],
 *
 * The backend reuses Laravel's connection settings but not its connection
 * objects: CacheerPHP keys, prefixes, and serializes on its own, so a client
 * configured with Laravel's key prefix or serializer would corrupt both.
 */
final readonly class StoreFactory
{
    public const array BACKENDS = ['array', 'file', 'database', 'redis'];

    private const int DEFAULT_L1_SECONDS = 60;

    public function __construct(private Application $app) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function make(array $config): CacheerStore
    {
        $clock = $this->clock();
        $codec = $this->pipeline($config)->codec();
        $store = $this->backend($config, $codec, $clock);

        if (isset($config['fallback'])) {
            $store = new ResilientStore($store, $this->backend(self::fallback($config['fallback']), $codec, $clock), null, $clock);
        }

        $l1 = self::l1Seconds($config['l1'] ?? null);
        $prefix = $this->prefix($config);

        if ($l1 === null) {
            return new CacheerStore(self::scoped(new Cacheer($store, $clock), $prefix), null, $prefix);
        }

        $tiered = Cacheer::tiered(new ArrayStore($clock), $store, Ttl::seconds($l1), $clock);

        // Lock records bypass the in-process tier: a release must read the owner
        // from the shared store, not from a copy another server has since replaced.
        return new CacheerStore(
            self::scoped($tiered, $prefix),
            self::scoped(new Cacheer($store, $clock), $prefix),
            $prefix,
        );
    }

    /**
     * Every database table the configured cacheer stores use, as
     * [connection, table] pairs, for the published migration.
     *
     * @param  array<string, mixed>  $stores  The `cache.stores` configuration.
     * @return list<array{0: string|null, 1: string}>
     */
    public static function databaseTables(array $stores): array
    {
        $tables = [];

        foreach ($stores as $config) {
            if (! is_array($config) || ($config['driver'] ?? null) !== 'cacheer') {
                continue;
            }

            $backends = [$config];

            if (isset($config['fallback'])) {
                $backends[] = self::fallback($config['fallback']);
            }

            foreach ($backends as $backend) {
                if (($backend['backend'] ?? 'file') === 'database') {
                    $pair = [self::optionalString($backend, 'connection'), self::string($backend, 'table', 'cacheer_store')];
                    $tables[implode("\0", [$pair[0] ?? '', $pair[1]])] = $pair;
                }
            }
        }

        return array_values($tables);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function backend(array $config, EnvelopeCodec $codec, Clock $clock): Store
    {
        $backend = $config['backend'] ?? 'file';

        return match ($backend) {
            'array' => new ArrayStore($clock),
            'file' => new FileStore(
                self::string($config, 'path', $this->app->storagePath('framework/cache/cacheer')),
                $codec,
                null,
                $clock,
            ),
            'database' => new DatabaseStore(
                $this->app->make('db')->connection(self::optionalString($config, 'connection'))->getPdo(),
                self::string($config, 'table', 'cacheer_store'),
                $codec,
                null,
                $clock,
            ),
            'redis' => new RedisStore(
                $this->redis(self::string($config, 'connection', 'default')),
                self::string($config, 'redis_prefix', 'cacheer'),
                $codec,
                null,
                $clock,
            ),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown CacheerPHP backend [%s]; use one of: %s.',
                is_scalar($backend) ? (string) $backend : get_debug_type($backend),
                implode(', ', self::BACKENDS),
            )),
        };
    }

    /**
     * A client for one of Laravel's redis connections, built without the
     * options that rewrite keys or values on the wire.
     */
    private function redis(string $name): RedisConnection
    {
        $config = (array) $this->app->make('config')->get('database.redis', []);
        $client = (string) Arr::pull($config, 'client', 'phpredis');

        // Only `clusters.<name>` makes a cluster; `options.cluster` merely picks
        // the clustering mode and is set in Laravel's default configuration.
        if (isset($config['clusters'][$name])) {
            throw new InvalidArgumentException("Redis clusters are not supported by the CacheerPHP redis backend (connection [{$name}]).");
        }

        foreach (['prefix', 'serializer', 'compression'] as $option) {
            unset($config['options'][$option], $config[$name][$option]);
        }

        $connection = (new RedisManager($this->app, $client, $config))->connection($name)->client();

        return match (true) {
            $connection instanceof ClientInterface => new PredisConnection($connection),
            $connection instanceof Redis => new PhpRedisConnection($connection),
            default => throw new InvalidArgumentException(sprintf(
                'The redis connection [%s] gave an unsupported client (%s).',
                $name,
                get_debug_type($connection),
            )),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function pipeline(array $config): PipelineConfig
    {
        $pipeline = PipelineConfig::default();
        $compress = $config['compress'] ?? false;

        if ($compress !== false) {
            $pipeline = is_int($compress) ? $pipeline->withGzip($compress) : $pipeline->withGzip();
        }

        if (isset($config['encryption'])) {
            $pipeline = $pipeline->withKeyring(self::keyring($config['encryption']));
        }

        if (isset($config['max_value_bytes'])) {
            $pipeline = $pipeline->withMaxValueBytes((int) $config['max_value_bytes']);
        }

        return $pipeline;
    }

    /**
     * An empty passphrase — usually an unset environment variable — would
     * encrypt everything under a key anyone can derive, so it is refused.
     */
    private static function keyring(mixed $encryption): Keyring
    {
        if (! is_array($encryption) || ! is_array($encryption['passphrases'] ?? null) || ! is_string($encryption['active'] ?? null)) {
            throw new InvalidArgumentException("CacheerPHP encryption needs ['active' => id, 'passphrases' => [id => passphrase]].");
        }

        $passphrases = [];

        foreach ($encryption['passphrases'] as $id => $passphrase) {
            if (! is_string($passphrase) || $passphrase === '') {
                throw new InvalidArgumentException("The CacheerPHP encryption passphrase [{$id}] is empty.");
            }

            $passphrases[(string) $id] = $passphrase;
        }

        return Keyring::fromPassphrases($passphrases, $encryption['active']);
    }

    /**
     * Laravel's own store convention: a per-store prefix, else `cache.prefix`.
     * It becomes the CacheerPHP scope, so flushing one store never touches another.
     *
     * @param  array<string, mixed>  $config
     */
    private function prefix(array $config): string
    {
        $prefix = $config['prefix'] ?? $this->app->make('config')->get('cache.prefix');

        return is_string($prefix) ? $prefix : '';
    }

    private function clock(): Clock
    {
        return $this->app->bound(Clock::class) ? $this->app->make(Clock::class) : new LaravelClock;
    }

    private static function scoped(Cacheer $cache, string $prefix): Cacheer
    {
        return $prefix === '' ? $cache : $cache->in($prefix);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fallback(mixed $fallback): array
    {
        if (is_string($fallback)) {
            return ['backend' => $fallback];
        }

        if (is_array($fallback)) {
            return $fallback;
        }

        throw new InvalidArgumentException('A CacheerPHP fallback is a backend name or a backend configuration array.');
    }

    private static function l1Seconds(mixed $l1): ?int
    {
        return match (true) {
            $l1 === null, $l1 === false => null,
            $l1 === true => self::DEFAULT_L1_SECONDS,
            is_int($l1) && $l1 > 0 => $l1,
            default => throw new InvalidArgumentException('CacheerPHP l1 is true, false, or a positive number of seconds.'),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function string(array $config, string $key, string $default): string
    {
        return self::optionalString($config, $key) ?? $default;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("The CacheerPHP option [{$key}] must be a string.");
        }

        return $value;
    }
}
