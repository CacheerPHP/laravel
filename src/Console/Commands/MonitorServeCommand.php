<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Console\Commands;

use Cacheer\Monitor\Console\Application as MonitorApplication;
use Illuminate\Console\Command;

class MonitorServeCommand extends Command
{
    /**
     * Command signature.
     */
    protected $signature = 'cacheer:monitor
        {--host= : Host address for the monitor dashboard}
        {--port= : Port for the monitor dashboard}
        {--events= : Path to the JSONL events file}';

    /**
     * Command description.
     */
    protected $description = 'Start the Cacheer Monitor dashboard';

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        if (! class_exists(MonitorApplication::class)) {
            $this->error('Cacheer Monitor is not available. Install cacheer-monitor to use this command.');

            return 1;
        }

        $host = (string) ($this->option('host') ?? config('cacheer.monitor.host', '127.0.0.1'));
        $port = (int) ($this->option('port') ?? config('cacheer.monitor.port', 9966));

        if ($port < 1 || $port > 65535) {
            $this->error("Invalid port: {$port}. Must be between 1 and 65535.");

            return 1;
        }

        $monitor = new MonitorApplication;
        $args = ['serve'];
        $args[] = '--host='.$host;
        $args[] = '--port='.$port;

        $events = $this->option('events') ?? config('cacheer.reporter.events');
        if ($events) {
            $args[] = '--events='.$events;
        }

        if ($this->option('quiet')) {
            $args[] = '--quiet';
        }

        return $monitor->run($args);
    }
}
