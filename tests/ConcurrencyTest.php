<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Tests;

final class ConcurrencyTest extends TestCase
{
    /**
     * @requires extension pcntl
     * @requires OSFAMILY Linux
     */
    public function testConcurrency(): void
    {
        $exitCode = null;
        $result = exec('php ' . __DIR__ . '/concurrency_test.php', $output, $exitCode);
        $output = implode("\n", $output);
        $this->assertStringNotContainsString('1', $output);
        $this->assertStringNotContainsString('2', $output);
        $this->assertNotFalse($result);
        $this->assertSame(0, $exitCode);
    }
}
