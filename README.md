# CacheerPHP for Laravel

A Laravel cache driver backed by [CacheerPHP](https://github.com/CacheerPHP/CacheerPHP).

Your application keeps using `Cache::`, `cache()`, rate limiters, unique jobs and
the scheduler exactly as before. Underneath, the store is CacheerPHP, so you get
its failover, tiered caching, encryption at rest and [Cacheer Monitor](https://github.com/CacheerPHP/monitor)
without changing a line of application code. Switching back is one line in
`config/cache.php`.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- CacheerPHP 6

## Installation

```bash
composer require cacheerphp/laravel
```

The service provider registers itself. Add a store to `config/cache.php` and make
it the default:

```php
'default' => env('CACHE_STORE', 'cacheer'),

'stores' => [
    'cacheer' => [
        'driver' => 'cacheer',
        'backend' => 'redis',
        'connection' => 'cache',   // a connection from config/database.php
    ],
],
```

## Configuration

### Backends

| `backend` | Uses | Options |
| --- | --- | --- |
| `file` (default) | The filesystem | `path` (default `storage/framework/cache/cacheer`) |
| `array` | Process memory, for tests | — |
| `database` | A Laravel database connection | `connection`, `table` (default `cacheer_store`) |
| `redis` | A Laravel redis connection | `connection` (default `default`), `redis_prefix` (default `cacheer`) |

For the database backend, publish and run the migration. It creates the tables of
every `cacheer` store that uses the database, as primary or fallback:

```bash
php artisan vendor:publish --tag=cacheer-migrations
php artisan migrate
```

### Failover

Keep serving while Redis is down:

```php
'cacheer' => [
    'driver' => 'cacheer',
    'backend' => 'redis',
    'connection' => 'cache',
    'fallback' => 'file',   // or a full backend array, e.g. ['backend' => 'database']
],
```

Reads and writes move to the fallback when Redis fails, and go back to Redis when
it recovers. Counters and locks never fall back: during the outage they fail
closed (a lock is not acquired) rather than hand out a second, unshared lock.

Failover state lives in each PHP process. A long-running process (an Octane
worker, a queue worker) remembers the keys it changed during the outage and
invalidates them on Redis before using it again. A process that ends does not:
under PHP-FPM every request is its own process, so a value changed or deleted
during an outage can be served again from Redis after it recovers, until it
expires. If that matters, keep TTLs short for such data, or run
`php artisan cache:clear` once Redis is back.

### Tiered caching

Keep recent values in process memory in front of the shared store:

```php
'l1' => 60,   // in-process copies are at most 60 seconds old; true means 60
```

This pays off in long-running workers (Octane, queue workers). Bulk invalidation
(`Cache::flush()`) reaches every worker at its next coherence check, at most five
seconds later; a single-key change made by another worker is seen within the
`l1` age at most. Locks always read the shared store, never a local copy.

### Encryption and compression

```php
'encryption' => [
    'active' => 'v1',
    'passphrases' => ['v1' => env('CACHEER_KEY')],
],
'compress' => true,            // or a gzip level, 1–9
'max_value_bytes' => 1048576,  // reject larger values on write and on read
```

Values are encrypted with AES-256-GCM and authenticated before they are read. To
rotate, add a new passphrase, make it `active`, and keep the old one until its
entries have expired. An empty passphrase, such as an unset `CACHEER_KEY`, is
refused at boot rather than used.

### Prefix

Like Laravel's own stores, the store uses its `prefix` option, or else
`cache.prefix`. It becomes the store's isolated keyspace: `Cache::flush()` clears
only that keyspace, and matching is literal, so flushing `app_1` never touches
`app_10`.

## Using CacheerPHP directly

`Cache::` covers most needs. For CacheerPHP features it cannot express, such as tag
invalidation or nested scopes, type-hint CacheerPHP's contract. It resolves to the
same cache as your default `cacheer` store (or the first `cacheer` store):

```php
use Silviooosilva\CacheerPhp\Contracts\Cache;

public function __construct(private Cache $cache) {}

$this->cache->in('catalog')->set('product:42', $product, 300);
$this->cache->tag('product:42', 'catalog');
$this->cache->flushTag('catalog');
```

## Monitoring

Install [Cacheer Monitor](https://github.com/CacheerPHP/monitor) and every cacheer
store reports to it, with no further setup.

## Testing

Entries expire on Laravel's clock, so `$this->travel(5)->minutes()` and
`Carbon::setTestNow()` expire CacheerPHP entries just as they expire entries in
Laravel's own stores. Use `'backend' => 'array'` for a store that lives only for
the test.

## Differences from Laravel's built-in stores

- **No `Cache::tags()`.** Laravel's tags and CacheerPHP's tags mean different
  things, so the driver does not pretend one is the other. Use CacheerPHP tags
  directly (see above).
- **Locks cost more round trips.** Laravel needs lock owners that another process
  can release (`restoreLock()`, `forceRelease()`), so a lock is a cache record
  guarded by a short CacheerPHP lock. Acquiring one on Redis takes four round
  trips where Laravel's Redis lock takes one. Releases stay owner-safe: a holder
  whose lock expired cannot release the next holder's lock.
- **Counters are whole numbers.** `Cache::increment('key', 1.5)` is refused rather
  than truncated, and incrementing a non-integer value fails without changing it.
- **Keys** are at most 1024 bytes and contain no control characters.
- **Redis clusters are not supported.** The redis backend opens its own connection
  from the Laravel connection's settings, ignoring Laravel's redis `prefix`,
  `serializer` and `compression` options, because CacheerPHP prefixes and
  serializes on its own.
- **Database connections are captured when the store is created.** In
  long-running workers, a connection that Laravel later reconnects is not
  followed; restart workers after a database failover.

## Upgrading from silviooosilva/cacheerphp-laravel 0.1

The 0.1 package wrapped CacheerPHP 4 and 5 behind its own `Cacheer` facade. This
package replaces it with a cache driver for CacheerPHP 6:

- Replace `Cacheer::putCache()` / `getCache()` calls with `Cache::put()` /
  `Cache::get()` (or the CacheerPHP contract shown above).
- Move `config/cacheer.php` settings into a `cacheer` store in `config/cache.php`.
- `cacheer:monitor`, `cacheer:events-clear` and `cacheer:diagnostics` are gone; use
  Cacheer Monitor and the `cacheer` CLI that ships with CacheerPHP.
- CacheerPHP 6 cannot read data written by CacheerPHP 5. Start with an empty cache,
  for example under a new `prefix`.

## Development

```bash
composer install
composer test
```

`composer test` runs Rector (dry run), Pint, PHPStan and PHPUnit. To include the
Redis tests, point them at a disposable server; they use database 15:

```bash
CACHEER_TEST_REDIS_PORT=6379 vendor/bin/phpunit
CACHEER_TEST_REDIS_CLIENT=phpredis CACHEER_TEST_REDIS_PORT=6379 vendor/bin/phpunit --filter redis
```

## License

MIT
