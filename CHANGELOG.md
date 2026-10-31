# Changelog

## 1.0.0 — Unreleased

First release as `cacheerphp/laravel`: CacheerPHP underneath Laravel's cache,
either beneath the stores an application already has, with the same results and
no configuration change, or as a `cacheer` driver on CacheerPHP's own stores.
It requires CacheerPHP 6.1.1 and replaces the unreleased 0.1 package
(`silviooosilva/cacheerphp-laravel`); see "Upgrading from 0.1" in the README.

### Added

- Laravel's own stores run through CacheerPHP. Reads and writes go through its
  engine in the store's native format, under the same keys, so existing
  entries stay readable and removing the package needs no flush. `add()`,
  counters, `touch()`, locks, and tags go straight to the native store and
  return its own results. Reads, writes, `add()`, counters, and `touch()` are
  reported to CacheerPHP telemetry and Cacheer Monitor.
- Stores the router cannot mirror exactly stay native: the `cacheer` driver,
  `array`, `null`, `failover`, `memo`, DynamoDB, Redis with a phpredis
  serializer or compression, and stores without `add()` or locks. Turn routing
  off for a store with `'cacheer' => false`, or for all with
  `cache.cacheer.route`. Code that checks `Cache::getStore()` against a
  concrete Laravel store class sees a routed store; its `native()` method
  returns the original.
- `php artisan cacheer:doctor` lists each store and whether it is routed.
- `cacheer` cache driver for Laravel 12 and 13, so `Cache::`, rate limiters,
  unique jobs, the scheduler, and isolatable commands run on CacheerPHP.
- Backends: `array`, `file`, `database`, and `redis`, configured in
  `config/cache.php`, with optional `fallback`, in-process `l1` tier,
  encryption, compression, and `max_value_bytes`.
- Atomic locks with owners that other processes can restore or force-release.
  Releases are owner-safe, and locks fail closed during an outage.
- Entries expire on Laravel's clock, so `travel()` and `Carbon::setTestNow()`
  work in tests.
- Publishable migration for the database backend.
- CacheerPHP's `Cache` contract is bound in the container.

### Fixed

- Lock guards and owner records both stay on the primary, including when a
  backend fails between acquiring the guard and reading or writing its owner.
  Outages fail closed without hiding programming or payload errors.
- Backend connections are deferred until an operation runs, so an unavailable
  primary can fall back at startup and retry connecting after recovery.
- Redis prefix, serializer, and compression options are removed from global,
  nested, and URL connection settings before CacheerPHP uses the connection.
- Published migrations use explicit table definitions so changing cache store
  configuration cannot redirect rollback to a different table.
- Laravel stores forward failover telemetry to CacheerPHP.
- `Cache::flush()` and `php artisan cache:clear` no longer release held locks:
  lock records live beside the store's keyspace instead of inside it. With an
  empty prefix, flushing still clears the whole backend, locks included.
- `Cache::putMany()` and `setMultiple()` accept numeric keys.
- The database backend follows Laravel's reconnects and disconnects instead of
  keeping the first PDO until the worker restarts.
- The `l1` tier and the `array` backend sweep out expired entries, so
  long-running workers no longer keep every key they ever held.
- Refreshing a restored lock without a lifetime keeps its current lifetime
  instead of making it permanent.
- `l1`, `compress`, and `max_value_bytes` accept numeric strings from `env()`,
  and `0` or an unset variable switches them off. Invalid values, and a prefix
  that cannot be a CacheerPHP scope, are refused with the option named.

### Removed

- The 0.1 `Cacheer` facade, `CacheerManager`, `config/cacheer.php`, and the
  `cacheer:monitor`, `cacheer:events-clear`, and `cacheer:diagnostics` commands.
