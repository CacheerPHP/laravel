<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class EventsClearCommand extends Command
{
    protected $signature = 'cacheer:events-clear {--endpoint=http://127.0.0.1:9966/api/events/clear}';
    protected $description = 'Rotate the Cacheer Monitor events file via HTTP API';

    public function handle(): int
    {
        $endpoint = (string) $this->option('endpoint');
        $response = Http::post($endpoint);
        if ($response->successful()) {
            $this->info('Events cleared successfully.');
            return 0;
        }
        $this->error('Failed to clear events. Status: ' . $response->status());
        return 1;
    }
}
