<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodingStyle\Rector\FuncCall\ClosureFromCallableToFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Property\RestoreDefaultNullToNullableTypePropertyRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\ValueObject\PhpVersion;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_82,
    ]);

    $rectorConfig->parallel();

    $rectorConfig->cacheClass(FileCacheStorage::class);
    if (is_dir('/tmp')) {
        $rectorConfig->cacheDirectory('/tmp/rector');
    }

    $rectorConfig->paths([
        __DIR__ . '/src/',
        __DIR__ . '/tests/',
    ]);

    $rectorConfig->autoloadPaths([
        __DIR__ . '/vendor/autoload.php',
    ]);

    $rectorConfig->bootstrapFiles([
        __DIR__ . '/vendor/codeigniter4/framework/system/Test/bootstrap.php',
    ]);

    if (is_file(__DIR__ . '/phpstan.neon.dist')) {
        $rectorConfig->phpstanConfigs([
            __DIR__ . '/phpstan.neon.dist',
        ]);
    }

    $rectorConfig->phpVersion(PhpVersion::PHP_82);
    $rectorConfig->importNames();

    $rectorConfig->skip([
        ClosureFromCallableToFirstClassCallableRector::class,
        ReadOnlyClassRector::class,
        ReadOnlyPropertyRector::class,
        RestoreDefaultNullToNullableTypePropertyRector::class,
    ]);
};
