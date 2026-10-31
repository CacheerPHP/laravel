<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests\Support;

use Illuminate\Cache\NoLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;

/**
 * A custom store written against Laravel's untyped contract: its writes return
 * null, its counters return strings, and its prefix can change.
 */
final class LooselyTypedStore implements LockProvider, Store
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function __construct(public string $prefix = 'a:') {}

    public function get($key): mixed
    {
        return $this->data[$this->prefix.$key] ?? null;
    }

    /**
     * @param  array<array-key, string>  $keys
     * @return array<array-key, mixed>
     */
    public function many(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * @return null
     */
    public function put($key, $value, $seconds): mixed
    {
        $this->data[$this->prefix.$key] = $value;

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return null
     */
    public function putMany(array $values, $seconds): mixed
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return null;
    }

    /**
     * @return null
     */
    public function add(mixed $key, mixed $value, mixed $seconds): mixed
    {
        return null;
    }

    /**
     * @return string
     */
    public function increment($key, $value = 1): mixed
    {
        return '7';
    }

    /**
     * @return string
     */
    public function decrement($key, $value = 1): mixed
    {
        return '5';
    }

    /**
     * @return null
     */
    public function forever($key, $value): mixed
    {
        return $this->put($key, $value, 0);
    }

    /**
     * @return int
     */
    public function touch($key, $seconds): mixed
    {
        return 1;
    }

    /**
     * @return null
     */
    public function forget($key): mixed
    {
        unset($this->data[$this->prefix.$key]);

        return null;
    }

    /**
     * @return null
     */
    public function flush(): mixed
    {
        $this->data = [];

        return null;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return new NoLock($name, $seconds, $owner);
    }

    public function restoreLock($name, $owner): Lock
    {
        return $this->lock($name, 0, $owner);
    }
}
