<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class EventsClearCommand extends Command
{
    /**
     * Command signature.
     */
    protected $signature = 'cacheer:events-clear {--endpoint= : Override the monitor API endpoint}';

    /**
     * Command description.
     */
    protected $description = 'Rotate the Cacheer Monitor events file via HTTP API';

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        $endpoint = $this->option('endpoint')
            ?? config('cacheer.monitor.endpoint', 'http://127.0.0.1:9966/api/events/clear');

        $timeout = (int) config('cacheer.monitor.timeout', 30);

        try {
            $response = Http::timeout($timeout)->post((string) $endpoint);

            if ($response->successful()) {
                $this->info('Events cleared successfully.');

                return 0;
            }

            $this->error('Failed to clear events. Status: '.$response->status());

            return 1;
        } catch (Exception $e) {
            $this->error('Failed to connect to monitor: '.$e->getMessage());

            return 1;
        }
    }
}
