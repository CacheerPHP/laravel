<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests\Feature;

use Cacheer\Laravel\StoreFactory;
use Cacheer\Laravel\Tests\Support\FailingPdo;
use Cacheer\Laravel\Tests\TestCase;
use Error;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use Silviooosilva\CacheerPhp\Exceptions\CorruptedPayloadException;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;
use Throwable;

/**
 * How a cacheer store behaves when a backend fails, recovers, or reconnects.
 */
final class FailureRecoveryTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function lockFailures(): array
    {
        return [
            'guard' => ['DELETE FROM cacheer_store_locks'],
            'owner read after guard' => ['FROM cacheer_store WHERE'],
            'owner write after guard' => ['INSERT INTO cacheer_store ('],
        ];
    }

    #[DataProvider('lockFailures')]
    public function test_a_lock_never_moves_its_owner_to_fallback(string $sql): void
    {
        $pdo = $this->database();
        $config = ['backend' => 'database', 'fallback' => 'array'];
        $factory = $this->app?->make(StoreFactory::class);
        $this->assertInstanceOf(StoreFactory::class, $factory);
        $store = $factory->make($config);
        $pdo->failOnSql = $sql;

        $this->assertFalse($store->lock('job', 60)->get());
        $this->assertNull($store->cache()->in('laravel-locks')->get('job'));

        $pdo->failOnSql = null;
        $pdo->down = false;
        // The outage also prevented releasing the short native guard lease.
        $this->travel(11)->seconds();
        $next = $factory->make($config)->lock('job', 60);
        $this->assertTrue($next->get());
        $this->assertTrue($next->release());
    }

    public function test_lock_release_and_refresh_fail_closed_during_an_outage(): void
    {
        $pdo = $this->database();
        $factory = $this->app?->make(StoreFactory::class);
        $this->assertInstanceOf(StoreFactory::class, $factory);
        $store = $factory->make(['backend' => 'database', 'fallback' => 'array']);
        $lock = $store->lock('job', 60);
        $this->assertTrue($lock->get());
        $pdo->down = true;

        $this->assertFalse($lock->refresh());
        $this->assertFalse($lock->release());
        $this->assertFalse($lock->isOwnedByCurrentProcess());
        $lock->forceRelease();

        $pdo->down = false;
        $this->assertFalse($store->lock('job', 60)->get());
        $this->assertTrue($lock->release());
    }

    /** @return array<string, array{class-string<Throwable>}> */
    public static function visibleErrors(): array
    {
        return [
            'invalid argument' => [InvalidArgumentException::class],
            'programming error' => [Error::class],
            'corrupted payload' => [CorruptedPayloadException::class],
        ];
    }

    /** @param class-string<Throwable> $exception */
    #[DataProvider('visibleErrors')]
    public function test_a_lock_does_not_hide_programming_or_payload_errors(string $exception): void
    {
        $pdo = $this->database();
        $factory = $this->app?->make(StoreFactory::class);
        $this->assertInstanceOf(StoreFactory::class, $factory);
        $store = $factory->make(['backend' => 'database', 'fallback' => 'array']);
        $pdo->failOnSql = 'FROM cacheer_store WHERE';
        $pdo->failure = new $exception('Invalid test operation.');

        $this->expectException($exception);

        $store->lock('job', 60)->get();
    }

    public function test_a_database_primary_can_connect_after_starting_offline(): void
    {
        $pdo = $this->database();
        $pdo->down = true;
        $attempts = 0;
        DB::connection()->setPdo(function () use ($pdo, &$attempts): FailingPdo {
            $attempts++;

            return $pdo->availableConnection();
        });
        $factory = $this->app?->make(StoreFactory::class);
        $this->assertInstanceOf(StoreFactory::class, $factory);
        $store = $factory->make(['backend' => 'database', 'fallback' => 'array']);
        $this->assertSame(0, $attempts);

        $store->put('offline', 'fallback', 60);
        $this->assertSame('fallback', $store->get('offline'));
        $this->assertFalse($store->lock('job', 60)->get());

        $pdo->down = false;
        $store->put('online', 'primary', 60);
        $this->assertSame('primary', $store->get('online'));
        $this->assertGreaterThan(1, $attempts);
        $rows = $pdo->query('SELECT COUNT(*) FROM cacheer_store');
        $this->assertInstanceOf(PDOStatement::class, $rows);
        $this->assertSame(1, (int) $rows->fetchColumn());
    }

    public function test_a_database_backend_follows_laravel_reconnects(): void
    {
        $dropped = $this->database();
        $factory = $this->app?->make(StoreFactory::class);
        $this->assertInstanceOf(StoreFactory::class, $factory);
        $store = $factory->make(['backend' => 'database']);
        $this->assertTrue($store->put('before', 'value', 60));

        // The server drops the connection, and Laravel reconnects with a new PDO.
        $dropped->down = true;
        $current = $this->database();

        $this->assertTrue($store->put('after', 'value', 60));
        $this->assertSame('value', $store->get('after'));
        $rows = $current->query('SELECT COUNT(*) FROM cacheer_store');
        $this->assertInstanceOf(PDOStatement::class, $rows);
        $this->assertSame(1, (int) $rows->fetchColumn());
    }

    public function test_a_database_backend_reconnects_after_laravel_disconnects(): void
    {
        $path = $this->directory().'/cache.sqlite';
        (new Filesystem)->ensureDirectoryExists(dirname($path));
        touch($path);
        Config::set('database.connections.cache_file', ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']);
        DatabaseStoreSchema::migrate(DB::connection('cache_file')->getPdo(), 'cacheer_store');
        $factory = $this->app?->make(StoreFactory::class);
        $this->assertInstanceOf(StoreFactory::class, $factory);
        $store = $factory->make(['backend' => 'database', 'connection' => 'cache_file']);

        DB::disconnect('cache_file');
        $this->assertTrue($store->put('key', 'value', 60));

        DB::disconnect('cache_file');
        $this->assertSame('value', $store->get('key'));
    }

    public function test_an_offline_database_fallback_does_not_prevent_primary_writes(): void
    {
        DB::connection()->setPdo(fn () => throw new PDOException('Fallback is offline.'));
        $factory = $this->app?->make(StoreFactory::class);
        $this->assertInstanceOf(StoreFactory::class, $factory);
        $store = $factory->make(['backend' => 'array', 'fallback' => 'database']);

        $this->assertTrue($store->put('key', 'primary', 60));
        $this->assertSame('primary', $store->get('key'));
    }

    /**
     * Give the default connection a migrated database that can be made to fail.
     *
     * @return FailingPdo The database.
     */
    private function database(): FailingPdo
    {
        $pdo = new FailingPdo;
        DatabaseStoreSchema::migrate($pdo, 'cacheer_store');
        DB::connection()->setPdo($pdo);

        return $pdo;
    }
}
