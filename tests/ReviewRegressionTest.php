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

use function is_resource;

use const PHP_OS_FAMILY;
use const E_ALL;
use const PHP_BINARY;
use const LOCK_EX;

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
        $this->collectGarbage($cache);
        $this->assertFalse($armed);
        $this->assertSame('fresh', $cache->get('key'));
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresOperatingSystemFamily('Linux')]
    #[RunInSeparateProcess]
    public function testPublisherCannotReplaceEntryBetweenGcCheckAndUnlink(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $file = null;
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
            ->willReturnCallback(function (string $path) use (&$file, &$collecting, &$interleaved, $waitFor): bool {
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
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
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
            $this->collectGarbage($cache);
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
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
        $blocked = $file;
        $this->collectGarbage($cache);
        $blocked = null;
        $this->assertFileExists($file);
        $this->assertSame('live', $cache->get('key'));
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

    public static function cleanupOperations(): array
    {
        return ['clear' => [false], 'gc' => [true]];
    }

    public static function directoryCleanupOperations(): array
    {
        $cases = [];
        foreach ([0, 1, 2] as $level) {
            foreach (self::cleanupOperations() as $operation => [$expiredOnly]) {
                $cases["$operation-level$level"] = [$level, $expiredOnly];
            }
        }
        return $cases;
    }

    public static function dotKeyCleanupOperations(): array
    {
        $cases = [];
        foreach (self::directoryCleanupOperations() as $name => [$level, $expiredOnly]) {
            foreach (['.foo', '.gitignore'] as $key) {
                $cases["$name-$key"] = [$level, $expiredOnly, $key];
            }
        }
        return $cases;
    }

    #[DataProvider('dotKeyCleanupOperations')]
    public function testCleanupRemovesDotKeyWithoutSuffix(int $level, bool $expiredOnly, string $key): void
    {
        MockHelper::$time = time();
        $cache = new FileCache(self::DIRECTORY, fileSuffix: '', directoryLevel: $level, gcProbability: 0);
        $cache->set($key, 1, 1);
        $file = $this->findCacheFile(self::DIRECTORY, $key);
        file_put_contents(self::DIRECTORY . '/.keep', '*');
        MockHelper::$time += 2;

        if ($expiredOnly) {
            $this->collectGarbage($cache);
        } else {
            $cache->clear();
        }

        $this->assertFileDoesNotExist($file);
        $this->assertSame('*', file_get_contents(self::DIRECTORY . '/.keep'));
    }

    public function testReplacementWhileOldFileIsOpen(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $this->assertTrue($cache->set('key', 'old'));
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
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
    }

    #[DataProvider('cleanupOperations')]
    public function testCleanupRetainsThePublicationLock(bool $expiredOnly): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $cache->set('key', 'value');
        $file = self::DIRECTORY . '/@gc.lock';
        $before = stat($file);
        MockHelper::$time = time() + 7200;

        if ($expiredOnly) {
            $this->collectGarbage($cache);
        } else {
            $cache->clear();
        }

        clearstatcache();
        $after = stat($file);
        $this->assertSame($before['ino'], $after['ino']);
        $this->assertSame($before['dev'], $after['dev']);
    }

    public static function lockOperations(): array
    {
        return ['write' => ['set'], 'gc' => ['gc']];
    }

    #[RunInSeparateProcess]
    #[DataProvider('lockOperations')]
    public function testExistingReadOnlyLockDoesNotRequireOwnership(string $operation): void
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
            $cache->set('key', 'old', 1);
            $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');

            if ($operation === 'gc') {
                MockHelper::$time += 2;
                $this->collectGarbage($cache);

                $this->assertFileDoesNotExist($file);
            } else {
                $result = $cache->set('key', 'new');

                $this->assertTrue($result);
                $this->assertSame('new', $cache->get('key'));
            }
        } finally {
            chmod($lock, 0666);
        }
    }

    public static function concurrentReadOperations(): array
    {
        return [['get'], ['has'], ['gc']];
    }

    public static function lockCreationScenarios(): array
    {
        return [
            'gc-existing' => ['gc', false],
            'gc-created-concurrently' => ['gc', true],
            'write-existing' => ['set', false],
            'write-created-concurrently' => ['set', true],
        ];
    }

    #[RunInSeparateProcess]
    #[DataProvider('lockCreationScenarios')]
    public function testExclusiveOperationUsesWritableLock(string $operation, bool $concurrentCreation): void
    {
        if ($operation === 'set' && PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Only Windows publication requires an exclusive lock.');
        }
        mkdir(self::DIRECTORY, recursive: true);
        $file = self::DIRECTORY . '/key.bin';
        file_put_contents($file, serialize('old'));
        touch($file, time() - 1);
        if (!$concurrentCreation) {
            touch(self::DIRECTORY . '/@gc.lock');
        }
        $this->getFunctionMock('Yiisoft\Cache\File', 'fopen')->expects($this->atLeastOnce())
            ->willReturnCallback(static function (string $path, string $mode) {
                if ($mode === 'x+b') {
                    touch($path);
                    return false;
                }
                return fopen($path, $mode);
            });
        $this->getFunctionMock('Yiisoft\Cache\File', 'flock')->expects($this->atLeastOnce())
            ->willReturnCallback(static function ($handle, int $operation): bool {
                // Linux NFS requires write access for an exclusive lock.
                if (($operation & LOCK_EX) !== 0 && !str_contains(stream_get_meta_data($handle)['mode'], '+')) {
                    return false;
                }
                return flock($handle, $operation);
            });
        $cache = new FileCache(self::DIRECTORY, directoryLevel: 0, gcProbability: 0);

        if ($operation === 'gc') {
            $this->collectGarbage($cache);

            $this->assertFileDoesNotExist($file);
        } else {
            $result = $cache->set('key', 'new');

            $this->assertTrue($result);
            $this->assertSame('new', $cache->get('key'));
        }
    }

    #[RunInSeparateProcess]
    #[DataProvider('lockCreationScenarios')]
    public function testOperationAcceptsReadOnlyLock(string $operation, bool $concurrentCreation): void
    {
        mkdir(self::DIRECTORY, recursive: true);
        $file = self::DIRECTORY . '/key.bin';
        file_put_contents($file, serialize('old'));
        touch($file, time() - 1);
        if (!$concurrentCreation) {
            touch(self::DIRECTORY . '/@gc.lock');
        }
        $this->getFunctionMock('Yiisoft\Cache\File', 'fopen')->expects($this->atLeastOnce())
            ->willReturnCallback(static function (string $path, string $mode) {
                if ($mode === 'r+b') {
                    return false;
                }
                if ($mode === 'x+b') {
                    touch($path);
                    return false;
                }
                return fopen($path, $mode);
            });
        $cache = new FileCache(self::DIRECTORY, directoryLevel: 0, gcProbability: 0);

        if ($operation === 'gc') {
            $this->collectGarbage($cache);

            $this->assertFileDoesNotExist($file);
        } else {
            $result = $cache->set('key', 'new');

            $this->assertTrue($result);
            $this->assertSame('new', $cache->get('key'));
        }
    }

    #[RunInSeparateProcess]
    public function testConcurrentLockCreationIsRetried(): void
    {
        $created = false;
        $this->getFunctionMock('Yiisoft\Cache\File', 'fopen')->expects($this->atLeastOnce())
            ->willReturnCallback(static function (string $path, string $mode) use (&$created) {
                if ($mode === 'x+b') {
                    // Another worker won the race to create the lock.
                    touch($path);
                    $created = true;
                    return false;
                }
                return fopen($path, $mode);
            });
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $this->assertTrue($cache->set('key', 'value'));
        $this->assertTrue($created);
        $this->assertSame('value', $cache->get('key'));
    }

    #[RunInSeparateProcess]
    public function testUnavailableLockPreservesExistingValue(): void
    {
        $blocked = false;
        $this->getFunctionMock('Yiisoft\Cache\File', 'fopen')->expects($this->atLeastOnce())
            ->willReturnCallback(static function (string $path, string $mode) use (&$blocked) {
                return $blocked && str_ends_with($path, '@gc.lock') ? false : fopen($path, $mode);
            });
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $this->assertTrue($cache->set('key', 'old'));
        $blocked = true;
        $this->assertFalse($cache->set('key', 'new'));
        $blocked = false;
        $this->assertSame('old', $cache->get('key'));
    }

    public static function lockFailures(): array
    {
        return [
            ['get', 'open'], ['get', 'lock'],
            ['has', 'open'], ['has', 'lock'],
            ['gc', 'open'], ['gc', 'lock'],
            ['clear', 'open'], ['clear', 'lock'],
        ];
    }

    #[RunInSeparateProcess]
    #[RequiresOperatingSystemFamily('Windows')]
    #[DataProvider('lockFailures')]
    public function testUnavailableLockPreservesEntry(string $operation, string $failure): void
    {
        $blocked = false;
        $stream = null;
        $this->getFunctionMock('Yiisoft\Cache\File', 'fopen')->expects($this->atLeastOnce())
            ->willReturnCallback(static function (string $path, string $mode) use (&$blocked, $failure) {
                return $blocked && $failure === 'open' && str_ends_with($path, '@gc.lock')
                    ? false
                    : fopen($path, $mode);
            });
        $this->getFunctionMock('Yiisoft\Cache\File', 'flock')->expects($this->atLeastOnce())
            ->willReturnCallback(static function ($handle, int $operation) use (&$stream, &$blocked, $failure): bool {
                if ($blocked && $failure === 'lock') {
                    $stream = $handle;
                    return false;
                }
                return flock($handle, $operation);
            });
        // Clearing a dot-key without a suffix must read its header to identify it as a cache entry.
        $key = $operation === 'clear' ? '.key' : 'key';
        $suffix = $operation === 'clear' ? '' : '.bin';
        $cache = new FileCache(self::DIRECTORY, fileSuffix: $suffix, gcProbability: 0);
        MockHelper::$time = time();
        $cache->set($key, 'value', 1);
        $file = $this->findCacheFile(self::DIRECTORY, $key . $suffix);
        $blocked = true;

        if ($operation === 'gc') {
            MockHelper::$time += 2;
            $this->collectGarbage($cache);
        } elseif ($operation === 'clear') {
            $cache->clear();
        } else {
            $result = $cache->$operation($key);

            $this->assertSame($operation === 'get' ? null : false, $result);
        }

        $this->assertFileExists($file);
        if ($failure === 'lock') {
            $this->assertNotNull($stream);
            $this->assertFalse(is_resource($stream));
        }
    }

    public static function permissionFailures(): array
    {
        return [['lock'], ['temporary']];
    }

    #[RunInSeparateProcess]
    #[DataProvider('permissionFailures')]
    public function testPermissionFailurePreservesOldValue(string $failure): void
    {
        $cache = new FileCache(self::DIRECTORY, fileMode: 0640, directoryLevel: 0, gcProbability: 0);
        mkdir(self::DIRECTORY, recursive: true);
        $this->getFunctionMock('Yiisoft\Cache\File', 'chmod')->expects($this->atLeastOnce())
            ->willReturnCallback(static function (string $path, int $mode) use ($failure): bool {
                $suffix = $failure === 'lock' ? '@gc.lock' : '.tmp';
                return str_ends_with($path, $suffix) ? false : chmod($path, $mode);
            });
        $file = self::DIRECTORY . '/key.bin';
        file_put_contents($file, serialize('old'));
        touch($file, time() + 3600);
        $this->assertFalse($cache->set('key', 'new'));
        $this->assertSame(serialize('old'), file_get_contents($file));
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
            ->willReturnCallback(function ($handle, int $length) use (&$armed, $waitFor): string|false {
                $header = fgets($handle, $length);
                if (!$armed) {
                    return $header;
                }
                $armed = false;
                touch(self::DIRECTORY . '/@start');
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
            // Start the worker before opening the reader, so it cannot inherit a held lock.
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/publication_writer.php', self::DIRECTORY, 'wait'],
                [1 => ['file', self::DIRECTORY . '/@output', 'w'], 2 => ['redirect', 1]],
                $pipes,
            );
            $this->assertIsResource($process);
            if ($operation === 'get') {
                $this->assertSame($old, $cache->get('key'));
            } elseif ($operation === 'has') {
                $this->assertTrue($cache->has('key'));
            } else {
                $this->collectGarbage($cache);
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
