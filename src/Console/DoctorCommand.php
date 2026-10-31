<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Console;

use Cacheer\Laravel\Routing\CacheerCacheManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Lists each cache store and whether it runs through CacheerPHP.
 */
final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'cacheer:doctor';

    /** @var string */
    protected $description = 'Show which cache stores run through CacheerPHP, and why others do not';

    /**
     * Build each configured store and report how it runs.
     *
     * @return int The exit code.
     */
    public function handle(): int
    {
        $manager = $this->laravel->make('cache');

        if (! $manager instanceof CacheerCacheManager) {
            $this->components->warn(sprintf('No store is routed: the cache manager is %s, from another package.', $manager::class));

            return self::SUCCESS;
        }

        $rows = [];

        foreach ((array) $this->laravel->make('config')->get('cache.stores', []) as $name => $config) {
            try {
                $manager->store((string) $name);
                $status = $manager->routing()[(string) $name] ?? 'built before routing was installed';
            } catch (Throwable $error) {
                $status = 'could not be built: '.$error->getMessage();
            }

            $rows[] = [
                (string) $name,
                is_array($config) && is_string($config['driver'] ?? null) ? $config['driver'] : '?',
                $status === CacheerCacheManager::ROUTED ? 'routed' : 'not routed: '.$status,
            ];
        }

        $this->table(['Store', 'Driver', 'CacheerPHP'], $rows);

        return self::SUCCESS;
    }
}
