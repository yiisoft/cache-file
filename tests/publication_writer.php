<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File;

use function dirname;

require dirname(__DIR__) . '/vendor/autoload.php';

// Signal immediately before publication tries to acquire its lock.
function flock($stream, int $operation): bool
{
    global $argv;
    file_put_contents($argv[1] . '/@prepared', '1');
    $nativeFlock = 'flock';
    return $nativeFlock($stream, $operation);
}

if (($argv[2] ?? '') === 'wait') {
    $deadline = microtime(true) + 10;
    while (!is_file($argv[1] . '/@start')) {
        if (microtime(true) >= $deadline) {
            exit(2);
        }
        usleep(1000);
        clearstatcache();
    }
}

$cache = new FileCache($argv[1], gcProbability: 0);
$success = $cache->set('key', str_repeat('new', 8192), 3600);
file_put_contents($argv[1] . '/@published', $success ? '1' : '0');
exit($success ? 0 : 1);
