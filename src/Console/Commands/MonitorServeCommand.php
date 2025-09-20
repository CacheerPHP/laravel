<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Console\Commands;

use Cacheer\Monitor\Console\Application as MonitorApplication;
use Illuminate\Console\Command;

class MonitorServeCommand extends Command
{
    protected $signature = 'cacheer:monitor {--host=127.0.0.1} {--port=9966} {--events=} {--quiet}';
    protected $description = 'Start the Cacheer Monitor dashboard';

    public function handle(): int
    {
        if (!class_exists(MonitorApplication::class)) {
            $this->error('Cacheer Monitor is not available. Install cacheer-monitor to use this command.');
            return 1;
        }

        $monitor = new MonitorApplication();
        $args = ['serve'];
        $args[] = '--host=' . $this->option('host');
        $args[] = '--port=' . (string) $this->option('port');
        if ($this->option('events')) {
            $args[] = '--events=' . $this->option('events');
        }
        if ($this->option('quiet')) {
            $args[] = '--quiet';
        }
        return $monitor->run($args);
    }
}
