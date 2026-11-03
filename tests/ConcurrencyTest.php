<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Tests;

use function escapeshellarg;
use function exec;

use const PHP_BINARY;

final class ConcurrencyTest extends TestCase
{
    /**
     * @requires extension pcntl
     * @requires OSFAMILY Linux
     */
    public function testConcurrency(): void
    {
        $exitCode = null;
        $result = exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/concurrency_test.php') . ' 2>&1', $output, $exitCode);
        $output = implode("\n", $output);
        $this->assertSame('', $output);
        $this->assertNotFalse($result);
        $this->assertSame(0, $exitCode);
    }
}
