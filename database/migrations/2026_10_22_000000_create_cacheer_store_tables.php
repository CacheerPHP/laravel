<?php

declare(strict_types=1);

use Cacheer\Laravel\StoreFactory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Silviooosilva\CacheerPhp\Stores\Support\DatabaseStoreSchema;

/**
 * Creates the tables of every cacheer store configured with the database
 * backend (as primary or fallback), on that store's connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (StoreFactory::databaseTables((array) Config::get('cache.stores', [])) as [$connection, $table]) {
            DatabaseStoreSchema::migrate(DB::connection($connection)->getPdo(), $table);
        }
    }

    public function down(): void
    {
        foreach (StoreFactory::databaseTables((array) Config::get('cache.stores', [])) as [$connection, $table]) {
            DatabaseStoreSchema::drop(DB::connection($connection)->getPdo(), $table);
        }
    }
};
