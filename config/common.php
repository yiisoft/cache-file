<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Cache\File\FileCache;

/* @var $params array */

return [
    CacheInterface::class => fn(Aliases $aliases) => new FileCache(
        $aliases->get($params['yiisoft/cache-file']['file-cache']['path'])
    ),
];
