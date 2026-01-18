<?php

declare(strict_types=1);

use Yiisoft\Aliases\Aliases;
use Yiisoft\Cache\File\FileCache;

/* @var $params array */

return [
    FileCache::class => static fn(Aliases $aliases) => new FileCache(
        cachePath: $aliases->get($params['yiisoft/cache-file']['fileCache']['path']),
        directoryMode: $params['yiisoft/cache-file']['fileCache']['directoryMode'],
        fileSuffix: $params['yiisoft/cache-file']['fileCache']['fileSuffix'],
        fileMode: $params['yiisoft/cache-file']['fileCache']['fileMode'],
        directoryLevel: $params['yiisoft/cache-file']['fileCache']['directoryLevel'],
        gcProbability: $params['yiisoft/cache-file']['fileCache']['gcProbability'],
    ),
];
