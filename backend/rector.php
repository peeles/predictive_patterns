<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Laravel\Set\LaravelSetList;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Set\ValueObject\LevelSetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/app',
        __DIR__ . '/database',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ]);

    $rectorConfig->cacheDirectory(__DIR__ . '/storage/framework/rector');
    $rectorConfig->phpVersion(80200);
    $rectorConfig->importNames();
    $rectorConfig->parallel();

    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_82,
        LaravelSetList::LARAVEL_120,
    ]);

    $rectorConfig->skip([
        ReadOnlyPropertyRector::class => [__DIR__ . '/database'],
    ]);
};
