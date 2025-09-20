<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

class Cacheer extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'cacheer';
    }
}
