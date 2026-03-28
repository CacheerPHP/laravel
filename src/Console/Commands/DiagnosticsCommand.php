<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Console\Commands;

use Cacheer\Laravel\Support\CacheerManager;
use Illuminate\Console\Command;

class DiagnosticsCommand extends Command
{
    /**
     * Command signature.
     */
    protected $signature = 'cacheer:diagnostics';

    /**
     * Command description.
     */
    protected $description = 'Display CacheerPHP configuration and environment info';

    /**
     * Execute the command.
     */
    public function handle(CacheerManager $manager): int
    {
        $config = config('cacheer', []);
        $cacheer = $manager->get();

        $this->table(
            ['Setting', 'Value'],
            [
                ['Driver', $config['default_driver'] ?? 'file'],
                ['Cache dir', $config['cache_dir'] ?? storage_path('cacheer')],
                ['Compression', ! empty($config['compress']) ? 'enabled' : 'disabled'],
                ['Encryption', ! empty($config['encryption_key']) ? 'enabled' : 'disabled'],
                ['Reporter', ! empty($config['reporter']['enabled']) ? 'enabled' : 'disabled'],
                ['Events file', $config['reporter']['events'] ?? 'unset'],
                ['Monitor host', $config['monitor']['host'] ?? '127.0.0.1'],
                ['Monitor port', (string) ($config['monitor']['port'] ?? 9966)],
                ['Cacheer class', $cacheer::class],
            ],
        );

        return 0;
    }
}
