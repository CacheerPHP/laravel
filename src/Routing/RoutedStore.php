<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Routing;

use Closure;
use Generator;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use LogicException;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Contracts\Cache;
use Silviooosilva\CacheerPhp\Contracts\Clock;
use Silviooosilva\CacheerPhp\Exceptions\InvalidKeyException;
use Silviooosilva\CacheerPhp\Exceptions\StoreOperationFailedException;
use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Observability\CacheEvent;
use Silviooosilva\CacheerPhp\Observability\SafeDispatch;
use Silviooosilva\CacheerPhp\Observability\Telemetry;
use Throwable;

/**
 * One of Laravel's stores, with its reads and writes running through
 * CacheerPHP's engine.
 *
 * Results match the native store's: values keep its format under the same
 * keys, a native false comes back as false, and a native exception surfaces
 * unchanged. Operations whose meaning differs between Laravel's stores — add,
 * counters, touch, and locks — go straight to the native store and return its
 * result as it is, as do keys CacheerPHP cannot represent and lifetimes of zero
 * or less. Writes through the engine report success as a boolean, even when a
 * custom store returns something else.
 *
 * Subclasses mirror the native store's optional Laravel interfaces, so the
 * framework's checks on the store give the same answers.
 */
class RoutedStore implements LockProvider, Store
{
    /** The store name in telemetry, as CacheerPHP's instrumentation names the adapter. */
    private const string TELEMETRY_STORE = 'LaravelStore';

    private readonly Cache $cache;

    /** Whether operations are reported; decided once, as CacheerPHP decides instrumentation. */
    private readonly bool $reports;

    /**
     * Route a Laravel store through a CacheerPHP cache over it.
     *
     * @param  Store&LockProvider  $native  The Laravel store.
     * @param  Clock  $clock  The clock CacheerPHP's engine runs on.
     */
    public function __construct(
        protected readonly Store&LockProvider $native,
        private readonly Clock $clock,
    ) {
        $this->cache = new Cacheer(new LaravelStore($native), $clock);
        $this->reports = Telemetry::hasListeners();
    }

    /**
     * Clone the native store too, as Laravel's repository expects of a store.
     */
    public function __clone()
    {
        $this->native = clone $this->native;
        $this->cache = new Cacheer(new LaravelStore($this->native), $this->clock);
        $this->reports = Telemetry::hasListeners();
    }

    /**
     * The Laravel store underneath.
     *
     * @return Store&LockProvider The native store.
     */
    public function native(): Store&LockProvider
    {
        return $this->native;
    }

    /**
     * Read a value.
     *
     * @param  string  $key  The cache key.
     * @return mixed The value, or null on a miss.
     */
    public function get($key): mixed
    {
        if (! self::routable($key)) {
            return $this->native->get($key);
        }

        return $this->engine(fn (): mixed => $this->cache->get((string) $key));
    }

    /**
     * Read several values at once.
     *
     * @param  array<array-key, string>  $keys  The cache keys.
     * @return mixed Each key's value, or null on a miss, by key.
     */
    public function many(array $keys): mixed
    {
        if (! self::allRoutable($keys)) {
            return $this->native->many($keys);
        }

        return $this->engine(fn (): array => $this->cache->many(array_map(strval(...), $keys)));
    }

    /**
     * Store a value for a number of seconds.
     *
     * @param  string  $key  The cache key.
     * @param  mixed  $value  The value to store.
     * @param  int  $seconds  The lifetime.
     * @return mixed Whether the native store stored it.
     */
    public function put($key, $value, $seconds): mixed
    {
        if ((int) $seconds <= 0 || ! self::routable($key)) {
            return $this->native->put($key, $value, $seconds);
        }

        return $this->write(fn () => $this->cache->set((string) $key, $value, (int) $seconds));
    }

    /**
     * Store several values for a number of seconds.
     *
     * @param  array<array-key, mixed>  $values  The values, by key.
     * @param  int  $seconds  The lifetime.
     * @return mixed Whether the native store stored them.
     */
    public function putMany(array $values, $seconds): mixed
    {
        if ((int) $seconds <= 0 || ! self::allRoutable(array_keys($values))) {
            return $this->native->putMany($values, $seconds);
        }

        return $this->write(fn () => $this->cache->setMany(self::stringKeys($values), (int) $seconds));
    }

    /**
     * Store a value that never expires.
     *
     * @param  string  $key  The cache key.
     * @param  mixed  $value  The value to store.
     * @return mixed Whether the native store stored it.
     */
    public function forever($key, $value): mixed
    {
        if (! self::routable($key)) {
            return $this->native->forever($key, $value);
        }

        return $this->write(fn () => $this->cache->forever((string) $key, $value));
    }

    /**
     * Remove a value.
     *
     * @param  string  $key  The cache key.
     * @return mixed Whether the native store removed it.
     */
    public function forget($key): mixed
    {
        if (! self::routable($key)) {
            return $this->native->forget($key);
        }

        return $this->engine(fn (): bool => $this->cache->delete((string) $key));
    }

    /**
     * Remove every entry, as the native store's flush() does.
     *
     * @return mixed Whether the native store flushed.
     */
    public function flush(): mixed
    {
        return $this->write(fn () => $this->cache->clear());
    }

    /**
     * Increase a counter, with the native store's semantics.
     *
     * @param  string  $key  The cache key.
     * @param  mixed  $value  The amount.
     * @return mixed The new value, or false if the native store refused.
     */
    public function increment($key, $value = 1): mixed
    {
        return $this->reported('increment', $key, fn (): mixed => $this->native->increment($key, $value));
    }

    /**
     * Decrease a counter, with the native store's semantics.
     *
     * @param  string  $key  The cache key.
     * @param  mixed  $value  The amount.
     * @return mixed The new value, or false if the native store refused.
     */
    public function decrement($key, $value = 1): mixed
    {
        return $this->reported('increment', $key, fn (): mixed => $this->native->decrement($key, $value));
    }

    /**
     * Extend a live entry's lifetime, with the native store's semantics.
     *
     * @param  string  $key  The cache key.
     * @param  int  $seconds  The new lifetime.
     * @return mixed Whether the native store extended it.
     */
    public function touch($key, $seconds): mixed
    {
        return $this->reported('touch', $key, fn (): mixed => $this->native->touch($key, $seconds));
    }

    /**
     * Store a value only when absent, with the native store's atomicity.
     *
     * @param  string  $key  The cache key.
     * @param  mixed  $value  The value to store.
     * @param  int  $seconds  The lifetime.
     * @return mixed Whether the value was stored.
     */
    public function add($key, $value, $seconds): mixed
    {
        $native = $this->native;

        // Only stores with add() are routed; Laravel's repository emulates it
        // for the others, which a router cannot mirror.
        if (! method_exists($native, 'add')) {
            throw new LogicException('The native cache store has no add().');
        }

        return $this->reported('set', $key, fn (): mixed => $native->add($key, $value, $seconds));
    }

    /**
     * Create one of the native store's locks.
     *
     * @param  string  $name  The lock name.
     * @param  int  $seconds  The lifetime; zero holds the lock until released.
     * @param  string|null  $owner  The owner token, or null for a new one.
     * @return Lock The lock, not yet acquired.
     */
    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return $this->native->lock($name, $seconds, $owner);
    }

    /**
     * Rebuild one of the native store's locks for its owner.
     *
     * @param  string  $name  The lock name.
     * @param  string  $owner  The owner token of the holder.
     * @return Lock The lock, owned by $owner.
     */
    public function restoreLock($name, $owner): Lock
    {
        return $this->native->restoreLock($name, $owner);
    }

    /**
     * The native store's key prefix.
     *
     * @return mixed The prefix.
     */
    public function getPrefix(): mixed
    {
        return $this->native->getPrefix();
    }

    /**
     * Forward any other method, such as connection(), to the native store.
     *
     * @param  string  $method  The method name.
     * @param  array<array-key, mixed>  $parameters  Its arguments.
     * @return mixed What the native store returns.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->native->{$method}(...$parameters);
    }

    /**
     * Run an engine operation, surfacing the native store's own exception.
     *
     * @template T
     *
     * @param  Closure(): T  $operation  The operation.
     * @return T Its result.
     */
    private function engine(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (StoreOperationFailedException $error) {
            throw self::nativeCause($error);
        }
    }

    /**
     * Run an engine write, returning the native store's false as false.
     *
     * @param  Closure(): mixed  $operation  The write.
     * @return bool Whether the native store accepted it.
     */
    private function write(Closure $operation): bool
    {
        try {
            $this->engine($operation);
        } catch (NativeOperationRefused) {
            return false;
        }

        return true;
    }

    /**
     * Run a native operation, reporting it to telemetry when anyone listens.
     *
     * @template T
     *
     * @param  string  $operation  The operation telemetry reports.
     * @param  mixed  $key  The cache key.
     * @param  Closure(): T  $call  The native call.
     * @return T Its result.
     */
    private function reported(string $operation, mixed $key, Closure $call): mixed
    {
        if (! $this->reports) {
            return $call();
        }

        $key = is_scalar($key) ? (string) $key : '';
        $start = microtime(true);

        try {
            $result = $call();
        } catch (Throwable $error) {
            SafeDispatch::to(Telemetry::dispatcher(), CacheEvent::failed(self::TELEMETRY_STORE, $key, self::elapsed($start), $error));

            throw $error;
        }

        if ($result !== false) {
            SafeDispatch::to(Telemetry::dispatcher(), CacheEvent::written(
                self::TELEMETRY_STORE,
                $key,
                self::elapsed($start),
                scope: '',
                operation: $operation,
            ));
        }

        return $result;
    }

    /**
     * The exception the native store threw, beneath the engine's wrapping.
     *
     * @param  StoreOperationFailedException  $error  The engine's exception.
     * @return Throwable The native exception, or $error when there is none.
     */
    private static function nativeCause(StoreOperationFailedException $error): Throwable
    {
        $cause = $error;

        while ($cause instanceof StoreOperationFailedException && $cause->getPrevious() !== null) {
            $cause = $cause->getPrevious();
        }

        return $cause;
    }

    /**
     * Whether CacheerPHP can represent a key; other keys go straight to the
     * native store.
     *
     * @param  mixed  $key  The cache key.
     * @return bool Whether the engine can handle it.
     */
    private static function routable(mixed $key): bool
    {
        if (! is_scalar($key)) {
            return false;
        }

        try {
            Key::named((string) $key);
        } catch (InvalidKeyException) {
            return false;
        }

        return true;
    }

    /**
     * Whether CacheerPHP can represent every key.
     *
     * @param  array<array-key, mixed>  $keys  The cache keys.
     * @return bool Whether the engine can handle all of them.
     */
    private static function allRoutable(array $keys): bool
    {
        foreach ($keys as $key) {
            if (! self::routable($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * PHP turns numeric array keys into integers, which CacheerPHP batches refuse.
     *
     * @param  array<array-key, mixed>  $values  The values, by key.
     * @return Generator<string, mixed> The same values, keyed by strings.
     */
    private static function stringKeys(array $values): Generator
    {
        foreach ($values as $key => $value) {
            yield (string) $key => $value;
        }
    }

    /**
     * Microseconds since a start time, the unit CacheerPHP events use.
     *
     * @param  float  $start  The start, from microtime(true).
     * @return float The elapsed microseconds.
     */
    private static function elapsed(float $start): float
    {
        return (microtime(true) - $start) * 1_000_000;
    }
}
