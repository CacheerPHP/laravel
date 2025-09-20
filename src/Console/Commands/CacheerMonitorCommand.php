<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests\Feature;

use Cacheer\Laravel\Tests\TestCase;

class MonitorCommandTest extends TestCase
{
    public function test_monitor_command_warns_when_monitor_missing(): void
    {
        $this->artisan('cacheer:monitor')->expectsOutput('Cacheer Monitor is not available. Install cacheer-monitor to use this command.')->assertExitCode(1);
    }
}
