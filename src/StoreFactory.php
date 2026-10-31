<?php

declare(strict_types=1);

namespace Cacheer\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use PDO;
use Predis\ClientInterface;
use Redis;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Config\PipelineConfig;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Contracts\RedisConnection;
use Silviooosilva\CacheerPhp\Contracts\Store;
use Silviooosilva\CacheerPhp\Exceptions\InvalidScopeException;
use Silviooosilva\CacheerPhp\Kernel\Scope;
use Silviooosilva\CacheerPhp\Kernel\Ttl;
use Silviooosilva\CacheerPhp\Observability\NullEventDispatcher;
use Silviooosilva\CacheerPhp\Observability\Telemetry;
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

    private const string LOCK_SCOPE = 'laravel-locks';

    /**
     * Create a factory over the application.
     *
     * @param  Application  $app  The application, for config, paths, and connections.
     */
    public function __construct(private Application $app) {}

    /**
     * Build a store from a `config/cache.php` entry.
     *
     * @param  array<string, mixed>  $config  The store's entry.
     * @return CacheerStore The store; backends connect on first use.
     */
    public function make(array $config): CacheerStore
    {
        $clock = $this->clock();
        $codec = $this->pipeline($config)->codec();
        $primary = $this->backend($config, $codec, $clock);
        $store = $primary;

        if (isset($config['fallback'])) {
            // The store reports outages and fallback service through its own
            // dispatcher, as Cacheer::resilient() wires it.
            $store = new ResilientStore(
                $store,
                $this->backend(self::fallback($config['fallback']), $codec, $clock),
                null,
                $clock,
                Telemetry::hasListeners() ? Telemetry::dispatcher() : new NullEventDispatcher,
            );
        }

        $l1 = self::l1Seconds($config['l1'] ?? null);
        $prefix = $this->prefix($config);

        $cache = $l1 === null
            ? new Cacheer($store, $clock)
            : Cacheer::tiered(self::local($clock), $store, Ttl::seconds($l1), $clock);

        // Lock records live beside the store's keyspace rather than inside it, so
        // Cache::flush() and cache:clear never release a held lock. Both the guard
        // and its owner record stay on the primary: falling back after taking the
        // guard could otherwise grant a lock only held locally.
        return new CacheerStore(
            self::scoped($cache, $prefix),
            self::scoped((new Cacheer($primary, $clock))->in(self::LOCK_SCOPE), $prefix),
            $prefix,
        );
    }

    /**
     * Build one backend, the primary or a fallback.
     *
     * @param  array<string, mixed>  $config  The backend's options.
     * @param  EnvelopeCodec  $codec  Encodes values for storage.
     * @param  Clock  $clock  Decides when entries expire.
     * @return Store The backend.
     */
    private function backend(array $config, EnvelopeCodec $codec, Clock $clock): Store
    {
        $backend = $config['backend'] ?? 'file';

        return match ($backend) {
            'array' => self::local($clock),
            'file' => new DeferredStore(fn (): FileStore => new FileStore(
                self::string($config, 'path', $this->app->storagePath('framework/cache/cacheer')),
                $codec,
                null,
                $clock,
            )),
            'database' => $this->databaseStore($config, $codec, $clock),
            'redis' => $this->redisStore($config, $codec, $clock),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown CacheerPHP backend [%s]; use one of: %s.',
                is_scalar($backend) ? (string) $backend : get_debug_type($backend),
                implode(', ', self::BACKENDS),
            )),
        };
    }

    /**
     * The PDO of one of Laravel's database connections, looked up on every
     * operation. Laravel replaces it on a reconnect (a lost connection,
     * `DB::reconnect()`) and drops it on `DB::disconnect()`; a store holding on
     * to the first one would fail until the worker restarted.
     *
     * @param  array<string, mixed>  $config  The backend's options.
     * @param  EnvelopeCodec  $codec  Encodes values for storage.
     * @param  Clock  $clock  Decides when entries expire.
     * @return DeferredStore The backend, connected on first use.
     */
    private function databaseStore(array $config, EnvelopeCodec $codec, Clock $clock): DeferredStore
    {
        $name = self::optionalString($config, 'connection');
        $table = self::string($config, 'table', 'cacheer_store');

        $pdo = function () use ($name): PDO {
            $connection = $this->app->make('db')->connection($name);
            $connection->reconnectIfMissingConnection();

            return $connection->getPdo();
        };

        return new DeferredStore(fn (): DatabaseStore => new DatabaseStore($pdo(), $table, $codec, null, $clock), $pdo);
    }

    /**
     * A client for one of Laravel's redis connections, built without the
     * options that rewrite keys or values on the wire.
     *
     * @param  array<string, mixed>  $store  The backend's options.
     * @param  EnvelopeCodec  $codec  Encodes values for storage.
     * @param  Clock  $clock  Decides when entries expire.
     * @return DeferredStore The backend, connected on first use.
     */
    private function redisStore(array $store, EnvelopeCodec $codec, Clock $clock): DeferredStore
    {
        $name = self::string($store, 'connection', 'default');
        $config = (array) $this->app->make('config')->get('database.redis', []);
        $client = (string) Arr::pull($config, 'client', 'phpredis');

        // Only `clusters.<name>` makes a cluster; `options.cluster` merely picks
        // the clustering mode and is set in Laravel's default configuration.
        if (isset($config['clusters'][$name])) {
            throw new InvalidArgumentException("Redis clusters are not supported by the CacheerPHP redis backend (connection [{$name}]).");
        }

        if (! isset($config[$name])) {
            throw new InvalidArgumentException("Redis connection [{$name}] not configured.");
        }

        // Parse URLs first: their query options can reintroduce a prefix or a
        // serializer even after the surrounding array was sanitized.
        $config[$name] = (new ConfigurationUrlParser)->parseConfiguration($config[$name]);

        foreach (['prefix', 'serializer', 'compression', 'compression_level'] as $option) {
            unset($config['options'][$option], $config[$name][$option], $config[$name]['options'][$option]);
        }

        $prefix = self::string($store, 'redis_prefix', 'cacheer');

        return new DeferredStore(fn (): RedisStore => new RedisStore(
            $this->redis(new RedisManager($this->app, $client, $config), $name),
            $prefix,
            $codec,
            null,
            $clock,
        ));
    }

    /**
     * Wrap a connection's client for CacheerPHP.
     *
     * @param  RedisManager  $manager  A manager over the sanitized redis settings.
     * @param  string  $name  The connection name.
     * @return RedisConnection The predis or phpredis client.
     */
    private function redis(RedisManager $manager, string $name): RedisConnection
    {
        $connection = $manager->connection($name)->client();

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
     * How values are serialized, compressed, encrypted, and size-limited.
     *
     * @param  array<string, mixed>  $config  The store's options.
     * @return PipelineConfig The pipeline.
     */
    private function pipeline(array $config): PipelineConfig
    {
        $pipeline = PipelineConfig::default();
        $compress = $config['compress'] ?? null;

        if ($compress === true) {
            $pipeline = $pipeline->withGzip();
        } else {
            $message = 'CacheerPHP compress is true, false, or a gzip level from 1 to 9.';
            $level = self::wholeNumber($compress, $message);

            if ($level !== null && ($level < 0 || $level > 9)) {
                throw new InvalidArgumentException($message);
            }

            // Level 0 stores without compressing, which is the same as no gzip.
            if ($level !== null && $level > 0) {
                $pipeline = $pipeline->withGzip($level);
            }
        }

        if (isset($config['encryption'])) {
            $pipeline = $pipeline->withKeyring(self::keyring($config['encryption']));
        }

        $message = 'CacheerPHP max_value_bytes is a number of bytes, or 0 for no limit.';
        $maxValueBytes = self::wholeNumber($config['max_value_bytes'] ?? null, $message);

        if ($maxValueBytes !== null) {
            if ($maxValueBytes < 0) {
                throw new InvalidArgumentException($message);
            }

            $pipeline = $pipeline->withMaxValueBytes($maxValueBytes);
        }

        return $pipeline;
    }

    /**
     * An empty passphrase — usually an unset environment variable — would
     * encrypt everything under a key anyone can derive, so it is refused.
     *
     * @param  mixed  $encryption  The `encryption` option.
     * @return Keyring The keys, with the active one selected.
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
     * @param  array<string, mixed>  $config  The store's options.
     * @return string The prefix, or an empty string for none.
     */
    private function prefix(array $config): string
    {
        $prefix = $config['prefix'] ?? $this->app->make('config')->get('cache.prefix');

        if (! is_string($prefix) || $prefix === '') {
            return '';
        }

        try {
            Scope::named($prefix);
        } catch (InvalidScopeException $error) {
            throw new InvalidArgumentException("The cache prefix [{$prefix}] cannot be a CacheerPHP scope: {$error->getMessage()}", 0, $error);
        }

        return $prefix;
    }

    /**
     * The clock bound in the container, else Laravel's.
     *
     * @return Clock The clock entries expire on.
     */
    private function clock(): Clock
    {
        return $this->app->bound(Clock::class) ? $this->app->make(Clock::class) : new LaravelClock;
    }

    /**
     * Scope a cache by the prefix, unless it is empty.
     *
     * @param  Cacheer  $cache  The cache to scope.
     * @param  string  $prefix  The prefix, or an empty string.
     * @return Cacheer The scoped cache.
     */
    private static function scoped(Cacheer $cache, string $prefix): Cacheer
    {
        return $prefix === '' ? $cache : $cache->in($prefix);
    }

    /**
     * Process memory, swept of expired entries.
     *
     * @param  Clock  $clock  Decides when entries expire.
     * @return PruningArrayStore The store.
     */
    private static function local(Clock $clock): PruningArrayStore
    {
        return new PruningArrayStore(new ArrayStore($clock), $clock);
    }

    /**
     * Normalize the `fallback` option to a backend's options.
     *
     * @param  mixed  $fallback  A backend name or a backend configuration array.
     * @return array<string, mixed> The backend's options.
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

    /**
     * The maximum age of in-process copies.
     *
     * @param  mixed  $l1  The `l1` option.
     * @return int|null Seconds, or null for no L1 tier.
     */
    private static function l1Seconds(mixed $l1): ?int
    {
        if ($l1 === true) {
            return self::DEFAULT_L1_SECONDS;
        }

        $message = 'CacheerPHP l1 is true, false, or a number of seconds.';
        $seconds = self::wholeNumber($l1, $message);

        return match (true) {
            $seconds === null, $seconds === 0 => null,
            $seconds > 0 => $seconds,
            default => throw new InvalidArgumentException($message),
        };
    }

    /**
     * A whole number from config, where values read through `env()` arrive as
     * strings. Null, false, and an empty string (an unset variable) mean unset.
     *
     * @param  mixed  $value  The option's value.
     * @param  string  $message  The error when it is not a whole number.
     * @return int|null The number, or null when unset.
     */
    private static function wholeNumber(mixed $value, string $message): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        $number = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        return is_int($number) ? $number : throw new InvalidArgumentException($message);
    }

    /**
     * A string option, or a default when it is unset.
     *
     * @param  array<string, mixed>  $config  The options.
     * @param  string  $key  The option name.
     * @param  string  $default  The value when unset.
     * @return string The value.
     */
    private static function string(array $config, string $key, string $default): string
    {
        return self::optionalString($config, $key) ?? $default;
    }

    /**
     * A string option.
     *
     * @param  array<string, mixed>  $config  The options.
     * @param  string  $key  The option name.
     * @return string|null The value, or null when unset.
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
