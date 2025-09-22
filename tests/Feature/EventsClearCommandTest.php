<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests\Feature;

use Cacheer\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class EventsClearCommandTest extends TestCase
{
    public function test_events_clear_success(): void
    {
        Http::fake([
            'http://127.0.0.1:9966/api/events/clear' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('cacheer:events-clear')
            ->expectsOutput('Events cleared successfully.')
            ->assertExitCode(0);
    }

    public function test_events_clear_failure(): void
    {
        Http::fake([
            'http://127.0.0.1:9966/api/events/clear' => Http::response('fail', 500),
        ]);

        $this->artisan('cacheer:events-clear')
            ->expectsOutput('Failed to clear events. Status: 500')
            ->assertExitCode(1);
    }
}
