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

### Removed

- The 0.1 `Cacheer` facade, `CacheerManager`, `config/cacheer.php`, and the
  `cacheer:monitor`, `cacheer:events-clear`, and `cacheer:diagnostics` commands.
