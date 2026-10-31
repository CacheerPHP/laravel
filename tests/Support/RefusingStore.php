<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests\Support;

use Illuminate\Cache\NoLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Throwable;

/**
 * A Laravel store whose writes return false, and whose every operation throws
 * {@see self::$failure} when one is set.
 */
final class RefusingStore implements LockProvider, Store
{
    public ?Throwable $failure = null;

    /**
     * @param  string  $key
     */
    public function get($key): mixed
    {
        $this->fail();

        return null;
    }

    /**
     * @param  array<array-key, string>  $keys
     * @return array<array-key, null>
     */
    public function many(array $keys): array
    {
        $this->fail();

        return array_fill_keys($keys, null);
    }

    public function put($key, $value, $seconds): bool
    {
        $this->fail();

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function putMany(array $values, $seconds): bool
    {
        $this->fail();

        return false;
    }

    public function add(mixed $key, mixed $value, mixed $seconds): bool
    {
        $this->fail();

        return false;
    }

    public function increment($key, $value = 1): bool
    {
        $this->fail();

        return false;
    }

    public function decrement($key, $value = 1): bool
    {
        $this->fail();

        return false;
    }

    public function forever($key, $value): bool
    {
        $this->fail();

        return false;
    }

    public function touch($key, $seconds): bool
    {
        $this->fail();

        return false;
    }

    public function forget($key): bool
    {
        $this->fail();

        return false;
    }

    public function flush(): bool
    {
        $this->fail();

        return false;
    }

    public function getPrefix(): string
    {
        return '';
    }

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return new NoLock($name, $seconds, $owner);
    }

    public function restoreLock($name, $owner): Lock
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Throw the configured failure, if any.
     */
    private function fail(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
