<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Cacheer\Laravel\Support\CacheerManager;

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
        $config = Config::get('cacheer', []);
        $events = $config['reporter']['events'] ?? 'unset';
        $this->line('Driver: <info>'.($config['default_driver'] ?? 'file').'</info>');
        $this->line('Cache dir: <info>'.($config['cache_dir'] ?? storage_path('cacheer')).'</info>');
        $this->line('Compression: <info>'.(! empty($config['compress']) ? 'enabled' : 'disabled').'</info>');
        $this->line('Encryption: <info>'.(! empty($config['encryption_key']) ? 'enabled' : 'disabled').'</info>');
        $this->line('Reporter enabled: <info>'.(! empty($config['reporter']['enabled']) ? 'yes' : 'no').'</info>');
        $this->line('Events file: <info>'.$events.'</info>');

        $cacheer = $manager->get();
        $this->line('Cacheer class: <info>'.$cacheer::class.'</info>');

        return 0;
    }
}
