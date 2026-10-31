<?php

declare(strict_types=1);

use Cacheer\Laravel\CacheerStore;
use Cacheer\Laravel\Routing\RoutedStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use Silviooosilva\CacheerPhp\Contracts\Cache as CacheerCache;

$application = $argv[1] ?? throw new InvalidArgumentException('Pass the application directory.');
require $application.'/vendor/autoload.php';
$app = require $application.'/bootstrap/app.php';

if (! $app instanceof Application) {
    throw new RuntimeException('The smoke fixture did not bootstrap Laravel.');
}

$app->make(Kernel::class)->bootstrap();

if (class_exists(TestCase::class)) {
    throw new RuntimeException('The consumer must run without package development dependencies.');
}

if (! $app->configurationIsCached()) {
    throw new RuntimeException('The smoke fixture must use cached configuration.');
}

$store = Cache::store()->getStore();

if (! $store instanceof CacheerStore) {
    throw new RuntimeException('Package discovery did not register the cacheer driver.');
}

Cache::put('smoke', ['value' => 42], 60);

if (Cache::get('smoke') !== ['value' => 42] || $app->make(CacheerCache::class)->get('smoke') !== ['value' => 42]) {
    throw new RuntimeException('Cache writes or the container binding did not round-trip.');
}

$lock = $store->lock('smoke', 60);

if (! $lock->get() || $store->lock('smoke', 60)->get() || ! $lock->release()) {
    throw new RuntimeException('Cache lock ownership did not behave correctly.');
}

Cache::flush();

if ($store->get('smoke') !== null) {
    throw new RuntimeException('Cache flush did not remove the value.');
}

// Laravel's own stores run through CacheerPHP, which needs a core release with
// entries of unknown age.
$native = Cache::store('native');

if (! $native->getStore() instanceof RoutedStore) {
    throw new RuntimeException('Laravel\'s own file store was not routed through CacheerPHP.');
}

$native->put('smoke', ['value' => 42], 60);

if ($native->get('smoke') !== ['value' => 42]) {
    throw new RuntimeException('A routed store did not round-trip.');
}

echo "Clean Laravel consumer passed with cached configuration.\n";
