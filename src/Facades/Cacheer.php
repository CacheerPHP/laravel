<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

class Cacheer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cacheer';
    }
}
