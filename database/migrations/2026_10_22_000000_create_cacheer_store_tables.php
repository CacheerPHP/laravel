<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;

/**
 * Edit this list before migrating to match your database backends, including
 * fallbacks. Keep it fixed afterward so rollback targets the original tables.
 */
return new class extends Migration
{
    /**
     * The connection (null for the default) and table of each database backend.
     *
     * @var list<array{0: string|null, 1: string}>
     */
    private array $tables = [[null, 'cacheer_store']];

    /**
     * Create the tables of each listed backend.
     */
    public function up(): void
    {
        foreach ($this->tables as [$connection, $table]) {
            DatabaseStoreSchema::migrate(DB::connection($connection)->getPdo(), $table);
        }
    }

    /**
     * Drop the tables of each listed backend.
     */
    public function down(): void
    {
        foreach (array_reverse($this->tables) as [$connection, $table]) {
            DatabaseStoreSchema::drop(DB::connection($connection)->getPdo(), $table);
        }
    }
};
