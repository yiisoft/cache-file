<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Tests;

use ErrorException;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Cache\File\MockHelper;
use RuntimeException;

use function is_array;

use const E_ALL;

require_once __DIR__ . '/MockHelper.php';

final class ReviewRegressionTest extends TestCase
{
    use PHPMock;

    private const DIRECTORY = __DIR__ . '/runtime/review';

    protected function tearDown(): void
    {
        MockHelper::$time = null;
        $this->removeDirectory(self::DIRECTORY);
    }

    #[RunInSeparateProcess]
    public function testGcKeepsEntryRefreshedAfterExpiryInspection(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $armed = false;
        $this->getFunctionMock('Yiisoft\Cache\File', 'fgets')->expects($this->atLeastOnce())
            ->willReturnCallback(static function ($handle, int $length) use ($cache, &$armed): string|false {
                $header = fgets($handle, $length);
                if ($armed) {
                    $armed = false;
                    $cache->set('key', 'fresh', 3600);
                }
                return $header;
            });
        MockHelper::$time = time();
        $cache->set('key', 'old', 1);
        MockHelper::$time += 2;
        $armed = true;
        $this->invokeMethod($cache, 'removeCacheFiles', [self::DIRECTORY, true]);
        $this->assertFalse($armed);
        $this->assertSame('fresh', $cache->get('key'));
    }

    /**
     * @requires extension pcntl
     * @requires OSFAMILY Linux
     */
    #[RunInSeparateProcess]
    public function testPublisherCannotReplaceEntryBetweenGcCheckAndUnlink(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $file = $this->invokeMethod($cache, 'getCacheFile', ['key']);
        $child = false;
        $collecting = false;
        $interleaved = false;
        $waitFor = static function (string $path): void {
            $deadline = microtime(true) + 5;
            do {
                clearstatcache();
                if (is_file($path)) {
                    return;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Timed out waiting for ' . $path);
        };
        $this->getFunctionMock('Yiisoft\Cache\File', 'file_put_contents')->expects($this->atLeastOnce())
            ->willReturnCallback(static function (string $path, string|array $data) use (&$child): int|false {
                $result = file_put_contents($path, $data);
                if ($child) {
                    touch(self::DIRECTORY . '/@prepared');
                }
                return $result;
            });
        $this->getFunctionMock('Yiisoft\Cache\File', 'unlink')->expects($this->atLeastOnce())
            ->willReturnCallback(function (string $path) use ($file, &$collecting, &$interleaved, $waitFor): bool {
                if ($collecting && $path === $file) {
                    $interleaved = true;
                    touch(self::DIRECTORY . '/@go');
                    $waitFor(self::DIRECTORY . '/@prepared');
                    usleep(20000);
                    clearstatcache();
                    $this->assertFileDoesNotExist(self::DIRECTORY . '/@published');
                }
                return unlink($path);
            });
        MockHelper::$time = time();
        $cache->set('key', 'expired', 1);
        MockHelper::$time += 2;
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            pcntl_alarm(10);
            $child = true;
            $waitFor(self::DIRECTORY . '/@go');
            if (!$cache->set('key', 'fresh', 3600)) {
                exit(1);
            }
            touch(self::DIRECTORY . '/@published');
            exit(0);
        }
        try {
            $collecting = true;
            $this->invokeMethod($cache, 'removeCacheFiles', [self::DIRECTORY, true]);
        } finally {
            touch(self::DIRECTORY . '/@go');
            pcntl_waitpid($pid, $status);
        }
        $this->assertTrue($interleaved);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame('fresh', $cache->get('key'));
    }

    #[RunInSeparateProcess]
    public function testGcKeepsEntryItCannotOpen(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $blocked = null;
        $this->getFunctionMock('Yiisoft\Cache\File', 'fopen')->expects($this->atLeastOnce())
            ->willReturnCallback(static function (string $path, string $mode) use (&$blocked) {
                return $path === $blocked ? false : fopen($path, $mode);
            });
        $cache->set('key', 'live', 3600);
        $file = $this->invokeMethod($cache, 'getCacheFile', ['key']);
        $blocked = $file;
        $this->invokeMethod($cache, 'removeCacheFiles', [self::DIRECTORY, true]);
        $blocked = null;
        $this->assertFileExists($file);
        $this->assertSame('live', $cache->get('key'));
    }

    #[RunInSeparateProcess]
    public function testSetMultipleReportsFailureAndStillAttemptsLaterEntries(): void
    {
        $this->getFunctionMock('Yiisoft\Cache\File', 'file_put_contents')->expects($this->atLeastOnce())
            ->willReturnCallback(static function (string $path, string|array $data): int|false {
                $record = is_array($data) ? implode('', $data) : $data;
                return str_ends_with($record, serialize('fail')) ? false : file_put_contents($path, $data);
            });
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $this->assertFalse($cache->setMultiple(['before' => 'ok', 'bad' => 'fail', 'after' => 'ok']));
        $this->assertSame('ok', $cache->get('before'));
        $this->assertSame('ok', $cache->get('after'));
        $this->assertFalse($cache->has('bad'));
    }

    /** @requires OSFAMILY Linux */
    public function testRetryWriteDoesNotEscapeAsWarningException(): void
    {
        $cache = new FileCache(self::DIRECTORY, directoryLevel: 0, gcProbability: 0);
        $cache->set('key', 'old');
        chmod(self::DIRECTORY, 0555);
        clearstatcache();
        if (is_writable(self::DIRECTORY)) {
            chmod(self::DIRECTORY, 0777);
            $this->markTestSkipped('Requires a user that cannot write to a read-only directory.');
        }
        $reporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message): bool {
            if (error_reporting() & $severity) {
                throw new ErrorException($message, 0, $severity);
            }
            return true;
        });
        try {
            $this->assertFalse($cache->set('key', 'new'));
        } finally {
            restore_error_handler();
            error_reporting($reporting);
            chmod(self::DIRECTORY, 0777);
        }
        $this->assertSame('old', $cache->get('key'));
    }

    public static function directoryLevels(): array
    {
        return [[0], [1], [2]];
    }

    #[DataProvider('directoryLevels')]
    public function testDotKeysWithoutSuffixAreClearedAndCollected(int $level): void
    {
        MockHelper::$time = time();
        $cache = new FileCache(self::DIRECTORY, fileSuffix: '', directoryLevel: $level, gcProbability: 0);
        $cache->set('.foo', 1);
        file_put_contents(self::DIRECTORY . '/.gitignore', '*');
        $this->assertTrue($cache->clear());
        $this->assertNull($cache->get('.foo'));
        $this->assertSame('*', file_get_contents(self::DIRECTORY . '/.gitignore'));
        $cache->set('.foo', 2, 1);
        MockHelper::$time += 2;
        $this->invokeMethod($cache, 'removeCacheFiles', [self::DIRECTORY, true]);
        $this->assertFileDoesNotExist($this->invokeMethod($cache, 'getCacheFile', ['.foo']));
        // The same dot filename is a cache entry when it contains our record.
        $cache->set('.gitignore', 'cached');
        $this->assertTrue($cache->clear());
        $this->assertNull($cache->get('.gitignore'));
    }

    public function testReplacementWhileOldFileIsOpen(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $this->assertTrue($cache->set('key', 'old'));
        $file = $this->invokeMethod($cache, 'getCacheFile', ['key']);
        $reader = fopen($file, 'rb');
        try {
            $this->assertTrue($cache->set('key', 'new'));
            $this->assertSame('new', $cache->get('key'));
            fgets($reader);
            $this->assertSame(serialize('old'), stream_get_contents($reader));
        } finally {
            fclose($reader);
        }
    }

    public function testClearAndGcRetainThePublicationLock(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $cache->set('key', 'value');
        $file = self::DIRECTORY . '/@gc.lock';
        $before = stat($file);
        $cache->clear();
        MockHelper::$time = time() + 7200;
        $this->invokeMethod($cache, 'removeCacheFiles', [self::DIRECTORY, true]);
        clearstatcache();
        $after = stat($file);
        $this->assertSame($before['ino'], $after['ino']);
        $this->assertSame($before['dev'], $after['dev']);
    }
}
