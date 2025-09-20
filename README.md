# CacheerPHP Laravel Integration

Package that brings CacheerPHP into Laravel and Lumen applications with zero fuss. It provides a service provider, facade, helper commands and optional bridges to the Cacheer Monitor dashboard.

## Installation

```bash
composer require silviooosilva/cacheerphp-laravel
```

Publish the configuration (Laravel only):

```bash
php artisan vendor:publish --provider="Cacheer\\Laravel\\CacheerLaravelServiceProvider" --tag="cacheer-config"
```

## Usage

Bind the cacheer manager with dependency injection or use the facade:

```php
use Cacheer\Laravel\Facades\Cacheer;

Cacheer::put('users:42', ['name' => 'Jane'], ttl: 300);
$profile = Cacheer::get('users:42');
```

The package honours `config/cache.php` defaults and exposes extra options in `config/cacheer.php`.

## Artisan Commands

- `cacheer:monitor` — wraps the builtin monitor (`--host`, `--port`, `--quiet`)
- `cacheer:events-clear` — rotates the JSONL events file
- `cacheer:diagnostics` — quick overview of configured drivers, events file, reporter status

## Documentation

Full docs live in `docs/en/updating/index.md` and `docs/en/contributing/index.md` (mirrored in PT). Refer there for upgrade paths, testing and contribution guidelines.

## Testing

```bash
composer install
composer test
```

## License

MIT
