# CacheerPHP Laravel Integration

Package that brings CacheerPHP into Laravel and Lumen applications with zero fuss. It provides a service provider, facade, helper commands and optional bridges to the Cacheer Monitor dashboard.

## Features

- Service provider that maps Laravel cache configuration to CacheerPHP.
- Facade (`Cacheer`) and container binding for dependency injection.
- Optional JSONL reporter integration for Cacheer Monitor.
- Artisan commands to serve the monitor, clear events and run diagnostics.
- Compatible with Laravel 10, 11, 12.

## Installation

### Developing locally (path repository)

```json
"repositories": [
  {
    "type": "path",
    "url": "../cacheerphp-laravel",
    "options": { "symlink": true }
  }
]
```

```bash
composer require silviooosilva/cacheerphp-laravel:dev-main --prefer-source
```

### When published on Packagist

```bash
composer require silviooosilva/cacheerphp-laravel
```

### Publish configuration (Laravel)

```bash
php artisan vendor:publish --provider="Cacheer\\Laravel\\CacheerLaravelServiceProvider" --tag="cacheer-config"
```

## Configuration

`config/cacheer.php` ships with:

| Key | Description |
| --- | --- |
| `default_driver` | Driver alias (`file`, `redis`, `array`, `database`). |
| `cache_dir` | Path used by file driver (defaults to `storage_path('cacheer')`). |
| `compress` | Enables payload compression. |
| `encryption_key` | Optional key to encrypt cached payloads. |
| `reporter.enabled` | Toggles JSONL reporter integration. |
| `reporter.events` | Location of the JSONL log consumed by Cacheer Monitor. |

## Usage

```php
use Cacheer\Laravel\Facades\Cacheer;

Cacheer::putCache('users:42', ['name' => 'Jane'], namespace: 'api', ttl: 300);

if (Cacheer::has('users:42', 'api')) {
    $profile = Cacheer::getCache('users:42', 'api');
}
```

Need the underlying instance? Inject `Cacheer\Laravel\Support\CacheerManager` and call `get()`.

## Artisan commands

- `cacheer:monitor` – wraps `cacheer-monitor serve` (`--host`, `--port`, `--events`, `--quiet`).
- `cacheer:events-clear` – POSTs to the monitor `events/clear` endpoint (override with `--endpoint`).
- `cacheer:diagnostics` – summary of driver, cache directory, reporter status and resolved class.

## Testing & QA

```bash
composer install
composer test
```

The `composer test` script runs Rector (dry-run), Pint, PHPStan and PHPUnit.

## Documentation

Contribution and upgrade notes live in the main [CacheerPHP docs](../docs/en/contributing/index.md) (Portuguese versions also available).

## License

MIT
