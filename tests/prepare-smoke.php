<?php

declare(strict_types=1);

// Configure a fresh Laravel application to consume the actual distribution ZIP.
$application = $argv[1] ?? throw new InvalidArgumentException('Pass the application directory.');
$archive = $argv[2] ?? throw new InvalidArgumentException('Pass the distribution ZIP.');
$manifest = $application.'/composer.json';
$config = readSmokeManifest($manifest);
$package = readSmokeManifest(__DIR__.'/../composer.json');
$package['version'] = '1.0.0';
$package['dist'] = ['type' => 'zip', 'url' => 'file://'.$archive];
$config['repositories'] = [['type' => 'package', 'package' => $package]];
$config['require']['cacheerphp/laravel'] = '^1.0';
file_put_contents($manifest, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

file_put_contents($application.'/.env', implode("\n", [
    'APP_NAME=CacheerSmoke',
    'APP_ENV=testing',
    'APP_KEY=base64:'.base64_encode(random_bytes(32)),
    'CACHE_STORE=cacheer',
    'SESSION_DRIVER=array',
    'QUEUE_CONNECTION=sync',
    'DB_CONNECTION=sqlite',
    'DB_DATABASE='.$application.'/database/database.sqlite',
    '',
]));
touch($application.'/database/database.sqlite');
file_put_contents($application.'/config/cache.php', <<<'PHP'
<?php

return [
    'default' => env('CACHE_STORE', 'cacheer'),
    'prefix' => 'smoke',
    'stores' => [
        'cacheer' => [
            'driver' => 'cacheer',
            'backend' => env('CACHEER_SMOKE_BACKEND', 'file'),
        ],
    ],
];

PHP);

/** @return array<mixed> */
function readSmokeManifest(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Cannot read the Composer manifest.');
    }

    $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($manifest)) {
        throw new RuntimeException('The Composer manifest must be an object.');
    }

    return $manifest;
}
