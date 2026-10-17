<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Tests;

use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Yiisoft\Cache\File\CacheException;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Cache\File\MockHelper;

use function dirname;

final class CleanupRaceTest extends TestCase
{
    use PHPMock;

    private const DIRECTORY = __DIR__ . '/runtime/cleanup-race';

    protected function tearDown(): void
    {
        MockHelper::$time = null;
        $this->removeDirectory(self::DIRECTORY);
    }

    public static function cleanupOperations(): array
    {
        return ['clear' => [false], 'gc' => [true]];
    }

    #[DataProvider('cleanupOperations')]
    #[RunInSeparateProcess]
    public function testCleanupIgnoresFileRemovedBeforeUnlink(bool $expiredOnly): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        MockHelper::$time = time();
        $cache->set('key', 'value', 1);
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
        MockHelper::$time += 2;
        $this->getFunctionMock('Yiisoft\Cache\File', 'unlink')
            ->expects($this->once())
            ->willReturnCallback(static function (string $path): bool {
                // Another process removes the file just before our unlink attempt.
                unlink($path);
                return false;
            });

        if ($expiredOnly) {
            $this->collectGarbage($cache);
        } else {
            $this->assertTrue($cache->clear());
        }

        $this->assertFileDoesNotExist($file);
    }

    public function testClearKeepsDirectories(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $cache->set('key', 'value');
        $directory = dirname($this->findCacheFile(self::DIRECTORY, 'key.bin'));

        $this->assertTrue($cache->clear());

        $this->assertFalse($cache->has('key'));
        $this->assertDirectoryExists($directory);
    }

    #[RunInSeparateProcess]
    public function testClearKeepsDirectoryPopulatedAfterScan(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $cache->set('ab1', 'old');
        $armed = true;
        $this->getFunctionMock('Yiisoft\Cache\File', 'closedir')
            ->expects($this->exactly(2))
            ->willReturnCallback(static function ($handle) use ($cache, &$armed): void {
                closedir($handle);
                if ($armed) {
                    $armed = false;
                    $cache->set('ab2', 'fresh');
                }
            });

        $this->assertTrue($cache->clear());

        $this->assertFalse($armed);
        $this->assertSame('fresh', $cache->get('ab2'));
    }

    #[DataProvider('cleanupOperations')]
    #[RunInSeparateProcess]
    public function testCleanupClosesDirectoriesWhenRemovalFails(bool $expiredOnly): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        MockHelper::$time = time();
        $cache->set('key', 'value', 1);
        MockHelper::$time += 2;
        $this->getFunctionMock('Yiisoft\Cache\File', 'unlink')
            ->expects($this->atLeastOnce())
            ->willReturn(false);
        $this->getFunctionMock('Yiisoft\Cache\File', 'closedir')
            ->expects($this->exactly(2))
            ->willReturnCallback(static function ($handle): void {
                closedir($handle);
            });

        $this->expectException(CacheException::class);

        if ($expiredOnly) {
            $this->collectGarbage($cache);
        } else {
            $cache->clear();
        }
    }
}
