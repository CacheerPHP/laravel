# Changelog

## 1.0.0 — Unreleased

First release as `cacheerphp/laravel`: a Laravel cache driver backed by
CacheerPHP 6. It replaces the unreleased 0.1 package
(`silviooosilva/cacheerphp-laravel`); see "Upgrading from 0.1" in the README.

### Added

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
- Laravel stores forward failover telemetry on CacheerPHP 6.1 while remaining
  compatible with 6.0.

### Removed

- The 0.1 `Cacheer` facade, `CacheerManager`, `config/cacheer.php`, and the
  `cacheer:monitor`, `cacheer:events-clear`, and `cacheer:diagnostics` commands.
