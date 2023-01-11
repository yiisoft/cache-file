<?php

/**
 * @author sartor
 * This file is a simple test for high concurrency heavy load for FileCache
 * run with `php concurrency_test.php`
 * Normal output is (100 times of dash symbol):
 * `----------------------------------------------------------------------------------------------------`
 * On high load it can be:
 * `2121-2-1-111-1-111211-12---211-111-12-1-1111-11-1--2111-1--122--122111111-112-1-221-211---1-211-2-2-`
 * `1111111--2-1-1-2-1-11-1--22--11212111-2-1121-1111--1-21211121-12-21-121--2--1211-11-1-11-1---211-11-`
 * `21121-1--1111221--1-1--12-2-12---11111----221-21--11-2--2122121--111111-1111--111-12-121-1221--111--`
 *
 * If `set` command failed `1` printed
 * If `delete` command failed `2` printed
 * It happens in commands like `chmod`, `touch` after file creation or deletion
 * To fix these problems `FileCache::isLastErrorNotSafe()` result handler created
 */
require __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

use Yiisoft\Cache\File\FileCache;

$cache = (new FileCache('/tmp/yii-cache'))
    ->withDirectoryLevel(2)
    ->withFileMode(0777);

if (!extension_loaded('pcntl')) {
    echo "no pcntl extension\n";
    exit(1);
}

$threadsTotal = 100;
foreach (range(1, $threadsTotal) as $i) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        echo "unable to fork\n";
    } else if ($pid === 0) {
        foreach (range(1, 10) as $j) {
            $result = $cache->set('k', $i);
            if ($result === false) {
                echo "1";
                exit(1);
            }
            $result = $cache->delete('k');
            if ($result === false) {
                echo "2";
                exit(1);
            }
        }
        echo "-";
        exit();
    }
}
pcntl_wait($status);
exit($status);
