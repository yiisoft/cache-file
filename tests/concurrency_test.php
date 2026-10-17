<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yiisoft\Cache\File\FileCache;

if (!\extension_loaded('pcntl')) {
    exit(1);
}

$directory = __DIR__ . '/runtime/stress-' . bin2hex(random_bytes(8));
$cache = new FileCache($directory, gcProbability: 0);
if (!$cache->set('key', str_repeat('seed', 1024))) {
    throw new RuntimeException('Cannot seed cache.');
}
$children = [];
for ($worker = 0; $worker < 6; $worker++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Cannot fork.');
    }
    if ($pid === 0) {
        pcntl_alarm(15);
        set_error_handler(static function (int $type, string $message): bool {
            if (!(error_reporting() & $type)) {
                return false;
            }
            throw new RuntimeException($message);
        });
        try {
            for ($i = 0; $i < 200; $i++) {
                if ($worker < 3) {
                    if (!$cache->set('key', str_repeat('writer-' . $worker, 1024), 60)) {
                        throw new RuntimeException('Concurrent write failed.');
                    }
                } elseif ($worker === 3) {
                    $i % 2 ? $cache->delete('key') : $cache->clear();
                } else {
                    $value = $cache->get('key');
                    if ($value !== null && !\in_array($value, [str_repeat('seed', 1024), str_repeat('writer-0', 1024), str_repeat('writer-1', 1024), str_repeat('writer-2', 1024)], true)) {
                        throw new RuntimeException('Observed partial or corrupt value.');
                    }
                }
            }
        } catch (Throwable $e) {
            fwrite(\STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        exit(0);
    }
    $children[] = $pid;
}
$failed = false;
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    $failed = $failed || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0;
}
$cache->clear();
$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($entries as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($directory);
exit($failed ? 1 : 0);
