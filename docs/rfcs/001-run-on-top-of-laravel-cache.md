# RFC-001: Run CacheerPHP on top of Laravel's existing cache

- Status: Accepted. PR-1 to PR-3 implemented: CacheerPHP branch
  `feat/unknown-entry-age`, `cacheerphp/laravel` branch
  `feat/run-on-laravel-cache`.
- Proposed: 2026-10-31
- Scope: `cacheerphp/laravel` 1.0.0, plus one CacheerPHP 6.x change (PR-1)
- Evidence: Laravel 13.38.0, CacheerPHP 6.0.0 and `6.x` @ 37aee31,
  `cacheerphp/laravel` 1.0.0 @ f65ded6

## Summary

Today `cacheerphp/laravel` is a cache driver: to use CacheerPHP, an application
moves its cache onto CacheerPHP's own stores. This RFC adds the opposite path,
the way Pest runs on top of PHPUnit. Installing the package puts CacheerPHP's
engine underneath the stores the application already has (Redis, Memcached,
database, file, and others), with the same results and no configuration change.
Features are then enabled per store, and CacheerPHP's own API becomes available
on the same backends.

## Motivation

Adopting CacheerPHP in Laravel today costs:

1. **A storage migration.** `CACHE_STORE=cacheer` starts from an empty cache in
   a new layout, and switching back loses what was written there.
2. **Changes to Laravel's behavior.** `Cache::tags()` throws, a lock takes four
   round trips on Redis instead of one, `add()`, counters, and rate limiters
   throw during a primary outage, and incrementing a stored `'5'` fails.
3. **Backend coverage.** Only array, file, database, and Redis. Memcached,
   DynamoDB, and custom drivers cannot be used.

Most of what CacheerPHP adds — telemetry and Monitor, failover with a circuit
breaker, an L1 tier, encryption at rest, scopes, and invalidation tags — does not
require owning the storage layout. A team should be able to install the package,
see its cache in Monitor the same day, opt into the rest one store at a time,
and uninstall without flushing anything if it changes its mind.

## Goals

1. Installing the package changes no result: every `Cache::` call returns what
   it returns today, against the same stores and data.
2. CacheerPHP's engine is in the path of ordinary cache calls, so Monitor sees
   them with timings.
3. Failover, L1, encryption, compression, and size limits are enabled per store
   by configuration.
4. CacheerPHP's API (scopes, invalidation tags, `flexible()`, policies) works on
   the application's existing backends, without a second backend.
5. In the default mode, removing the package needs no flush.

## Non-goals

- Changing Laravel's API, or what Laravel's tags mean.
- Emulating a guarantee a native store does not give. CacheerPHP RFC-002 rules
  this out: atomic operations and locks never receive best-effort emulation.
- Serving data written in one format as if it were another.
- Replacing the `cacheer` driver. It stays for applications that want
  CacheerPHP's own storage.

## Findings

### F1 — One place sees every store

`CacheManager::repository(Store $store, array $config)` is public, and every
built-in driver creates its repository through it, including `failover`, `null`,
`Cache::build()`, and `Cache::memo()`. Custom drivers do by convention
(`Cache::repository(new MongoStore)`). A `CacheManager` subclass that overrides
it can wrap each store as it is created, with no configuration change.

`CacheServiceProvider` is deferred, so binding `cache` from a package would be
undone when the deferred provider loads. The subclass has to be installed with
`$app->extend('cache', …)`, carrying over custom creators registered before it.

### F2 — Framework code inspects the store's class

| Location | Check | With a decorator |
| --- | --- | --- |
| `Bus\UniqueLock`, `Queue\CallQueuedHandler`, `Cache\MemoizedStore` | `instanceof LockProvider` | Same answer if the decorator mirrors `LockProvider` |
| `Repository::supportsTags()` | `method_exists($store, 'tags')` | Same answer if the decorator mirrors `tags()` |
| `CacheCommandMutex`, `CacheEventMutex`, `CacheSchedulingMutex` | `instanceof LockProvider && ! instanceof DynamoDbStore` | **Cannot be mirrored.** A wrapped DynamoDB store would move the scheduler and `--isolated` from `add()` to locks |
| `RateLimiter::withoutSerializationOrCompression()` | `instanceof RedisStore`, then a phpredis connection | **Cannot be mirrored.** With a phpredis serializer or compression configured, rate-limit counters would be stored serialized and `increment()` would fail |
| `FailoverStore::flushStaleTags()` | `instanceof RedisStore` | Stale tag sets of a wrapped Redis store are not pruned through the `failover` driver |

### F3 — Native stores disagree where CacheerPHP is strict

| Store | `increment()` on a missing key | `flush()` | Laravel tags |
| --- | --- | --- | --- |
| Redis | Starts at 0 (`INCRBY`) | `FLUSHDB` on the cache connection | Yes |
| Memcached | `false` | Flushes the whole server | Yes |
| DynamoDB | `false` (conditional update) | Throws | No |
| Database | `false` (row lock in a transaction) | Deletes every row of the table | No |
| File | Starts at 0; read-modify-write without a lock | Deletes the cache directory's contents | No |
| Array | Starts at 0; process-local | Empties the process's array | Yes |

All six implement `LockProvider` and `touch()`. None offers compare-and-swap. A
native `get()` returns `null` for a miss, so a cached `null` cannot be told
apart from one; Laravel's repository already treats both as a miss.

Passing these operations through unchanged keeps Laravel's behavior exactly.
Routing them through CacheerPHP's engine changes it, which is the cost of the
current driver listed under Motivation.

### F4 — Native entries have no age

Native stores do not record when an entry was written, and do not return its
expiry. `CacheEntry::createdAt()` already returns `?int`, but `CacheEntry::hit()`
requires an `int`. The freshness check in `CacheOperations`,
`$now < ($entry->createdAt() ?? $now) + $seconds`, treats an unknown creation
time as "now", so `flexible()` would serve an entry of unknown age as fresh
forever.

Features that depend on age or expiry — `flexible()`, the L1 tier, and TTLs in
telemetry — cannot work on native-format entries.

### F5 — Laravel already reports cache activity

The repository fires `CacheHit`, `CacheMissed`, `KeyWritten`, `KeyForgotten`,
`CacheFlushed`, their `Retrieving`, `Writing`, and `Failed` counterparts, and
`CacheFailedOver`. They carry the store name and key, but no duration.

## Proposal

Three modes. Only the first is on by default.

```
Cache:: (Laravel Repository, unchanged)
  └─ RoutedStore             mirrors the native store's Laravel interfaces
       ├─ value path ───────► Cacheer engine ─► LaravelStore adapter ─► native store
       │                      (telemetry and failover; Mode 3: envelopes and L1)
       └─ pass-through ─────► native store
            add, increment, decrement, touch, lock, restoreLock, tags,
            flushLocks, getPrefix, and any other method (__call)
```

### Mode 1 — Pass-through (default on install)

- `CacheerCacheManager extends CacheManager` overrides `repository()` and wraps
  the store in a `RoutedStore`, except for:
  - the `cacheer` driver's own `CacheerStore`;
  - `MemoizedStore`, `FailoverStore`, and `NullStore`, whose inner stores are
    wrapped already or which store nothing;
  - `DynamoDbStore`, and Redis stores whose phpredis connection sets a
    serializer or compression (F2);
  - stores without `add()` or `LockProvider`, such as `ArrayStore`: Laravel's
    repository emulates `add()` for them with its own events, which a router
    cannot reproduce;
  - stores with `'cacheer' => false`, and every store when
    `cache.cacheer.route` is false.

  Excluded stores keep their native behavior. Observing them through Laravel's
  events (F5) needs CacheerPHP events with an unknown duration; until then they
  are not reported.
- If `cache` already resolves to another package's `CacheManager` subclass, the
  package does not replace it and routes nothing.
- `RoutedStore` declares `tags()`, and `CanFlushLocks` on Laravel versions that
  have it, exactly when the native store does; every routed store has `add()`
  and `LockProvider`. Every check in F2 that can be mirrored gives the same answer.
- The value path — `get`, `many`, `put`, `putMany`, `forever`, `forget`, and
  `flush` — runs through a `Cacheer` over `LaravelStore`. The adapter stores
  values in the native format under the same keys, so Mode 1 writes exactly what
  the native store writes, and reads see existing entries.
- Native results are preserved. A native `false` becomes a typed exception
  inside the engine and `false` again at `RoutedStore`, so the repository fires
  the same `KeyWriteFailed` event and returns the same value.
- Every other operation passes straight to the native store (F3). `RoutedStore`
  reports it to telemetry with its duration.
- `LaravelStore` declares its capabilities through `CapabilityAware` (table
  below) and returns entries of unknown age (PR-1).
- Failover is optional: `'cacheer' => ['fallback' => 'file']` names another
  Laravel store, and the value path becomes a `ResilientStore` over the two
  adapters. Pass-through operations stay on the primary and behave as they do
  today during an outage.

What Mode 1 adds: Monitor with timings for every routed store, and failover by
configuration. It changes no stored data, and removing the package needs no flush.

### Mode 2 — CacheerPHP's API on the same backends (opt-in)

When the application has no `cacheer` driver store, the package binds
`Silviooosilva\CacheerPhp\Contracts\Cache` to a `Cacheer` over `LaravelStore` on
a configured store (the default store unless set). Its entries use CacheerPHP's
envelope format under a reserved key namespace, `cacheer:` by default. Envelopes
carry creation time, expiry, and tags, so the whole API works:

```php
use Silviooosilva\CacheerPhp\Contracts\Cache;

final class ProductCatalog
{
    public function __construct(private readonly Cache $cache) {}

    public function products(string $category): array
    {
        $catalog = $this->cache->in('catalog');
        $products = $catalog->flexible("products:{$category}", 60, 300, fn (): array => $this->load($category));
        $catalog->tag("products:{$category}", "category:{$category}");

        return $products;
    }

    public function forgetCategory(string $category): void
    {
        $this->cache->in('catalog')->flushTag("category:{$category}");
    }
}
```

- A scope is a generation key in the reserved namespace. Clearing it is one
  atomic increment (under a native lock where the store's `increment()` is not
  atomic), and never touches native keys or other scopes.
- A tag keeps an index key, updated under a native lock. `flushTag()` deletes
  only entries whose envelope still carries the tag, so a newer untagged value
  under the same key survives, and it returns the number it removed.
- Encryption, compression, size limits, and an L1 tier are configured as on the
  current driver.
- A value in the namespace that is not an envelope is rejected with CacheerPHP's
  typed exceptions, never served.

### Mode 3 — Enveloped `Cache::` (opt-in per store)

`'cacheer' => ['envelope' => true, …]` on a store writes envelopes for ordinary
`Cache::` calls under the reserved namespace instead of the native keys, so
encryption, compression, size limits, and L1 apply to them.

- `add`, `increment`, `decrement`, and `touch` must then go through the engine
  as well: a native `increment()` on the native key would not see the
  enveloped value. They take CacheerPHP's semantics — a missing counter starts
  at zero, amounts are whole numbers, and they fail during an outage instead of
  falling back — which are the differences the `cacheer` driver documents today.
- `tags()` returns Laravel's generic `TaggedCache` over the routed store, so
  tagged entries are enveloped too. Redis's set-based tag tracking is not used;
  a tag flush leaves old entries to expire, as on Laravel's other taggable stores.
- Locks stay native.
- Enabling starts the store cold. Disabling returns to the native keys, which
  may hold values from before Mode 3, so it requires `php artisan cache:clear`.

Mode 3 is where this RFC meets the current driver: the same semantics, on any
Laravel store.

### Capabilities of `LaravelStore`

| Capability | Mode 1 (native format) | Modes 2 and 3 (envelopes) |
| --- | --- | --- |
| Batch reads and writes | Native `many()` and `putMany()`; a cached `null` reads as a miss | Yes |
| Counters and compare-and-swap (`AtomicStore`) | Not declared; `increment()` passes through with native semantics (F3) | Under a native lock |
| Locks | Native locks | Native locks |
| Touch | Not declared; Laravel's `touch()` passes through, and a forever lifetime has no native form | Under a native lock, so the envelope's expiry changes too |
| Scoped clear | Not declared; native keys have no scope | Generation keys |
| Invalidation tags | Not declared; Laravel's `tags()` passes through | Tag index plus envelope metadata |
| Inspect and prune | Not declared | Not declared |
| Root `clear()` | Native `flush()` (F3) | The reserved namespace only |

A guarantee made under a native lock holds as far as that lock reaches:

| Store | Lock reach |
| --- | --- |
| Redis, Memcached, database, DynamoDB | Every server sharing the backend |
| File | One host |
| Array | One process |

An undeclared capability raises `UnsupportedCapabilityException`, as on any
CacheerPHP store.

### Configuration

All of it is optional. Without it, every store runs in Mode 1.

```php
// config/cache.php
'cacheer' => [
    'route' => env('CACHEER_ROUTE', true),  // false: observe through events only
    'namespace' => 'cacheer',                // reserved key prefix for Modes 2 and 3
    'contract_store' => null,                // Mode 2: the store behind the Cache contract
],

'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
        'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        'cacheer' => [
            'fallback' => 'file',            // another Laravel store
            'envelope' => true,              // Mode 3: changes stored data
            'l1' => 30,
            'encryption' => ['active' => 'v1', 'passphrases' => ['v1' => env('CACHEER_KEY')]],
        ],
    ],
],
```

`php artisan cacheer:doctor` lists each store, its mode, and the reason for any
exclusion, like Monitor's `doctor`.

### CacheerPHP change (PR-1)

- `CacheEntry::hit()` accepts `?int $createdAt`; the accessor already returns
  `?int`.
- The freshness check in `CacheOperations` treats an unknown creation time as
  stale instead of as "now", so `flexible()` recomputes rather than serving
  data of unknown age as fresh.

No existing store can produce an unknown creation time, so neither change
alters current behavior.

## Rollout

| PR | Repository | Change |
| --- | --- | --- |
| PR-1 | CacheerPHP `6.x` | Entries of unknown age (above) |
| PR-2 | `cacheerphp/laravel` | `LaravelStore` in native format, with capability reporting, tested against array, file, database, and Redis |
| PR-3 | `cacheerphp/laravel` | `CacheerCacheManager`, `RoutedStore`, exclusions, telemetry for pass-through operations, and `cacheer:doctor`; the equivalence suite |
| PR-4 | `cacheerphp/laravel` | Failover between Laravel stores |
| PR-5 | `cacheerphp/laravel` | Mode 2: envelopes, the reserved namespace, scopes, tags, and the contract binding |
| PR-6 | `cacheerphp/laravel` | Mode 3 |
| PR-7 | `docs` | English and Portuguese guides |

PR-3 is the first release users see. Each later PR can ship on its own.

## Rejected alternatives

- **Observe through Laravel's events only.** No risk and no data change, but no
  timings, and with CacheerPHP outside the path, failover and the API are
  impossible. Kept as the fallback for excluded stores.
- **A wrapper driver per store** (`'driver' => 'cacheer-wrap', 'store' => 'redis'`).
  Explicit and simpler, but every store must be edited, which defeats goal 1.
- **Routing counters, `add()`, and locks through the engine in Mode 1.** It
  changes Laravel's behavior on every store but Redis (F3), and replaces
  one-round-trip native locks with CacheerPHP's guarded lock records.
- **A decorator object bound as `cache` instead of a subclass.** The facade
  would accept it, but packages type-hint `Illuminate\Cache\CacheManager`
  (this package's own provider does).
- **Emulating missing capabilities**, such as compare-and-swap through get and
  set, or tags on the database store in Mode 1. Ruled out by CacheerPHP RFC-002.
- **Serving native entries from the reserved namespace** to warm Mode 3. An
  encrypting pipeline would accept unauthenticated plaintext; CacheerPHP rejects
  non-envelope data by design.

## Compatibility impact

- Mode 1 changes no stored data and no result, but every cache call takes a new
  path on install. It ships in `cacheerphp/laravel` 1.0.0, before any release,
  so no installed version changes behavior. It requires the CacheerPHP release
  that contains PR-1.
- The `cacheer` driver is unchanged and never routed.
- Excluded stores (F2) and applications whose `cache` is another package's
  subclass keep native behavior.
- Application code that checks `Cache::getStore()` against a concrete Laravel
  store class, as F2's framework code does, sees a `RoutedStore`. Its
  `native()` method returns the original store.
- Modes 2 and 3 write only under the reserved namespace. A key the application
  writes under the same prefix would collide, so the prefix is configurable.
- Mode 3 is cold when enabled and needs `cache:clear` when disabled.
- Telemetry names every routed store `LaravelStore`, because CacheerPHP names a
  store by its class. Telling a Redis store from a file store in Monitor needs a
  CacheerPHP change that lets a store report its own name.

## Measurements

Median per operation, PHP 8.5.11 CLI on Apple M4, Redis on loopback through
predis, CacheerPHP `6.x` with PR-1, 5,000 calls per round, seven rounds:

| Store | Operation | Native | Routed, no listener | Routed, listener |
| --- | --- | --- | --- | --- |
| File | `get()` miss | 2.0 µs | 3.7 µs | 4.7 µs |
| File | `get()` hit | 20.1 µs | 21.8 µs | 23.5 µs |
| Redis | `get()` miss | 22.9 µs | 23.7 µs | 24.7 µs |
| Redis | `put()` | 24.8 µs | 26.4 µs | 29.0 µs |

Routing adds about 1.5 µs per operation, and 2.5 µs with a telemetry listener.
I/O noise is of the same size on the other cases. Mode 1 stays the default.

## Security impact

- Mode 1 stores nothing new and adds no cryptography. Telemetry carries keys;
  values only with Monitor's capture, which stays off by default.
- Modes 2 and 3 use CacheerPHP's envelope pipeline: authenticated encryption when
  configured, typed rejection of foreign, truncated, or oversized payloads, and
  no plaintext accepted by an encrypting pipeline.
- Generation and tag-index keys live under the reserved namespace and match
  literally, so a scope or tag never reaches native keys.
- Without encryption, envelopes use `PhpSerializer`, which trusts the backend,
  as Laravel's own stores do.

## Tests

- **Equivalence (Mode 1).** The same scenarios run against each native store
  (file, database, and Redis with both clients) with and without routing,
  comparing return values and Laravel events, and each side reads what the
  other wrote. They cover `remember()`, `flexible()`, `many()` and `putMany()`
  with numeric keys, cached `null`, `add()`, counters on missing keys,
  `flush()`, keys CacheerPHP cannot represent, tags, locks with
  `restoreLock()`, unique jobs, the scheduler mutex, rate limiting, and
  `Cache::memo()`. The `failover` driver is not covered yet.
- **Exclusions.** The `cacheer` driver, `array`, a Redis store with a phpredis
  serializer, an opted-out store, and routing turned off are left native with
  their reason. Another package's `CacheManager` subclass is left in place.
  The DynamoDB exclusion needs the AWS SDK and is not tested.
- **Capabilities.** `LaravelStore` declares exactly the table above, and an
  undeclared capability raises `UnsupportedCapabilityException`. CacheerPHP's
  store conformance suite runs against envelope mode.
- **Failover.** Outage and recovery across two Laravel stores, as in the current
  `FailureRecoveryTest`.
- **PR-1.** An entry of unknown age is never fresh for `flexible()`, and existing
  stores are unaffected.

## Decisions

1. Mode 1 is on by default, with `cache.cacheer.route` to turn it off.
2. This ships in `cacheerphp/laravel` 1.0.0, which is not released yet.
3. Per-store settings live inside each store's array.

## Open questions

1. Memcached and DynamoDB in CI: add a Memcached service, and DynamoDB through a
   local emulator, or document DynamoDB as untested? Neither runs locally today;
   the DynamoDB exclusion is untested because the AWS SDK is not installed.
2. When a `cacheer` driver store exists, should Mode 2 still bind the contract
   to a native store if configured to?
3. Should CacheerPHP accept events of unknown duration, so excluded stores can
   be observed through Laravel's events?
