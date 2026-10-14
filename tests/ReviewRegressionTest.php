<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Tests;

use ErrorException;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Cache\File\MockHelper;
use RuntimeException;

use function is_array;

use function is_resource;

use const PHP_OS_FAMILY;
use const E_ALL;
use const PHP_BINARY;

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
    #[RequiresOperatingSystem('^(?!WIN)')]
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

    #[RequiresPhpExtension('pcntl')]
    #[RequiresOperatingSystemFamily('Linux')]
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
            if (PHP_OS_FAMILY === 'Windows') {
                // An external handle does not participate in the cache's read locks.
                $this->assertFalse($cache->set('key', 'new'));
                $this->assertSame('old', $cache->get('key'));
            } else {
                $this->assertTrue($cache->set('key', 'new'));
                $this->assertSame('new', $cache->get('key'));
            }
            fgets($reader);
            $this->assertSame(serialize('old'), stream_get_contents($reader));
        } finally {
            fclose($reader);
        }
        $this->assertTrue($cache->set('key', 'new'));
        $this->assertSame('new', $cache->get('key'));
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

    #[DataProvider('directoryLevels')]
    public function testCleanupPreservesUnrelatedHiddenDirectories(int $level): void
    {
        $cache = new FileCache(self::DIRECTORY, directoryLevel: $level, gcProbability: 0);
        $paths = [self::DIRECTORY . '/.git/objects', self::DIRECTORY . '/ab/.config'];
        $paths[] = self::DIRECTORY . str_repeat('/ab', $level) . '/.x';
        foreach ($paths as $path) {
            mkdir($path, recursive: true);
            file_put_contents($path . '/keep', 'unrelated');
            touch($path . '/keep', time() - 3600);
        }
        $this->assertTrue($cache->set('.foo', 'cached'));
        $this->invokeMethod($cache, 'removeCacheFiles', [self::DIRECTORY, true]);
        $this->assertTrue($cache->clear());
        $this->assertNull($cache->get('.foo'));
        foreach ($paths as $path) {
            $this->assertSame('unrelated', file_get_contents($path . '/keep'));
        }
    }

    #[RunInSeparateProcess]
    public function testExistingReadOnlyLockDoesNotRequireOwnership(): void
    {
        mkdir(self::DIRECTORY, recursive: true);
        $lock = self::DIRECTORY . '/@gc.lock';
        touch($lock);
        chmod($lock, 0444);
        $this->getFunctionMock('Yiisoft\Cache\File', 'chmod')->expects($this->atLeastOnce())
            ->willReturnCallback(function (string $path, int $mode) use ($lock): bool {
                $this->assertNotSame(str_replace('\\', '/', $lock), str_replace('\\', '/', $path));
                return chmod($path, $mode);
            });
        try {
            MockHelper::$time = time();
            $cache = new FileCache(self::DIRECTORY, fileMode: 0640, gcProbability: 0);
            $this->assertTrue($cache->set('key', 'old', 1));
            $this->assertTrue($cache->set('key', 'new', 1));
            $this->assertSame('new', $cache->get('key'));
            MockHelper::$time += 2;
            $this->invokeMethod($cache, 'removeCacheFiles', [self::DIRECTORY, true]);
            $this->assertFileDoesNotExist($this->invokeMethod($cache, 'getCacheFile', ['key']));
        } finally {
            chmod($lock, 0666);
        }
    }

    public static function concurrentReadOperations(): array
    {
        return [['get'], ['has'], ['gc']];
    }

    #[RunInSeparateProcess]
    #[DataProvider('concurrentReadOperations')]
    public function testPublicationDuringRead(string $operation): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $old = str_repeat('old', 8192);
        MockHelper::$time = time();
        $this->assertTrue($cache->set('key', $old, $operation === 'gc' ? 1 : 3600));
        MockHelper::$time += 2;
        $armed = true;
        $process = null;
        $waitFor = static function (string $file): void {
            $deadline = microtime(true) + 10;
            do {
                clearstatcache(true, $file);
                if (is_file($file)) {
                    return;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Timed out waiting for ' . $file);
        };
        $this->getFunctionMock('Yiisoft\Cache\File', 'fgets')->expects($this->atLeastOnce())
            ->willReturnCallback(function ($handle, int $length) use (&$armed, &$process, $waitFor): string|false {
                $header = fgets($handle, $length);
                if (!$armed) {
                    return $header;
                }
                $armed = false;
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/publication_writer.php', self::DIRECTORY],
                    [1 => ['file', self::DIRECTORY . '/@output', 'w'], 2 => ['redirect', 1]],
                    $pipes,
                );
                $this->assertIsResource($process);
                $waitFor(self::DIRECTORY . '/@prepared');
                if (PHP_OS_FAMILY === 'Windows') {
                    usleep(100000);
                    clearstatcache();
                    $this->assertFileDoesNotExist(self::DIRECTORY . '/@published');
                } else {
                    $waitFor(self::DIRECTORY . '/@published');
                }
                return $header;
            });
        try {
            if ($operation === 'get') {
                $this->assertSame($old, $cache->get('key'));
            } elseif ($operation === 'has') {
                $this->assertTrue($cache->has('key'));
            } else {
                $this->invokeMethod($cache, 'removeCacheFiles', [self::DIRECTORY, true]);
            }
        } finally {
            if (is_resource($process)) {
                $deadline = microtime(true) + 10;
                do {
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(1000);
                } while (microtime(true) < $deadline);
                if ($status['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
                $this->assertFalse($status['running'], 'Publisher did not finish after the reader closed.');
                $this->assertSame(0, $status['exitcode'], file_get_contents(self::DIRECTORY . '/@output'));
            }
        }
        $this->assertFalse($armed);
        $this->assertSame('1', file_get_contents(self::DIRECTORY . '/@published'));
        $this->assertSame(str_repeat('new', 8192), $cache->get('key'));
    }
}
