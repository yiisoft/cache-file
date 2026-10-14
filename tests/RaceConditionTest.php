<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Tests;

use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Cache\File\MockHelper;

use function basename;
use function clearstatcache;
use function dirname;
use function glob;
use function is_file;
use function time;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Simulates another process interfering with the cache in the middle of an operation.
 *
 * Every test runs in a separate process because a function can't be mocked after it was called.
 */
final class RaceConditionTest extends TestCase
{
    use PHPMock;

    private const MOCK_NAMESPACE = 'Yiisoft\Cache\File';
    private const CACHE_DIRECTORY = __DIR__ . '/runtime/race';

    protected function tearDown(): void
    {
        MockHelper::$time = null;
        $this->removeDirectory(dirname(self::CACHE_DIRECTORY));
    }

    #[RunInSeparateProcess]
    public function testItemDeletedByAnotherProcessDuringSetIsNotCorrupted(): void
    {
        $cache = new FileCache(self::CACHE_DIRECTORY);
        $file = null;
        $armed = false;
        $raced = false;

        $this
            ->getFunctionMock(self::MOCK_NAMESPACE, 'rename')
            ->expects($this->any())
            ->willReturnCallback(
                static function (string $filename, string $destination) use (&$file, &$armed, &$raced): bool {
                    if ($armed && is_file($file)) {
                        $raced = unlink($file);
                    }
                    return rename($filename, $destination);
                },
            );

        $cache->set('key', 'old');
        $file = $this->findCacheFile(self::CACHE_DIRECTORY, 'key.bin');
        $armed = true;
        $cache->set('key', 'new');
        clearstatcache();

        $this->assertTrue($raced, 'The race was not simulated.');
        $this->assertContains($cache->get('key', 'default'), ['new', 'default']);
    }

    #[RunInSeparateProcess]
    public function testSetFailsWithoutCorruptingItemWhenTemporaryFileIsRemoved(): void
    {
        $cache = new FileCache(self::CACHE_DIRECTORY);
        $file = null;
        $armed = false;
        $raced = false;

        $this
            ->getFunctionMock(self::MOCK_NAMESPACE, 'rename')
            ->expects($this->any())
            ->willReturnCallback(
                static function (string $filename, string $destination) use (&$armed, &$raced): bool {
                    if ($armed) {
                        $raced = unlink($filename);
                    }
                    return rename($filename, $destination);
                },
            );

        $cache->set('key', 'old');
        $file = $this->findCacheFile(self::CACHE_DIRECTORY, 'key.bin');
        $armed = true;
        $result = $cache->set('key', 'new');
        clearstatcache();

        $this->assertTrue($raced, 'The race was not simulated.');
        $this->assertFalse($result);
        $this->assertSame('old', $cache->get('key'));
        $this->assertSame([$file], glob(dirname($file) . DIRECTORY_SEPARATOR . '*'));
    }

    #[RunInSeparateProcess]
    #[RequiresOperatingSystem('^(?!WIN)')]
    public function testItemIsAvailableWhileAnotherProcessOverwritesIt(): void
    {
        $cache = new FileCache(self::CACHE_DIRECTORY);
        $armed = false;
        $valueSeenByReader = null;

        $this
            ->getFunctionMock(self::MOCK_NAMESPACE, 'rename')
            ->expects($this->any())
            ->willReturnCallback(
                static function (string $filename, string $destination) use (&$armed, &$valueSeenByReader): bool {
                    if ($armed) {
                        clearstatcache();
                        $valueSeenByReader = (new FileCache(self::CACHE_DIRECTORY))->get('key', 'default');
                    }
                    return rename($filename, $destination);
                },
            );

        $cache->set('key', 'old');
        $armed = true;
        $cache->set('key', 'new');

        $this->assertNotNull($valueSeenByReader, 'The race was not simulated.');
        $this->assertContains($valueSeenByReader, ['old', 'new']);
    }

    #[RunInSeparateProcess]
    public function testGcIgnoresFileRemovedByAnotherProcess(): void
    {
        $cache = new FileCache(self::CACHE_DIRECTORY, gcProbability: 0);
        $expiredFile = null;
        $raced = false;

        $this
            ->getFunctionMock(self::MOCK_NAMESPACE, 'str_starts_with')
            ->expects($this->any())
            ->willReturnCallback(
                static function (string $haystack, string $needle) use (&$expiredFile, &$raced): bool {
                    if ($expiredFile !== null && $haystack === basename($expiredFile) && is_file($expiredFile)) {
                        $raced = unlink($expiredFile);
                    }
                    return str_starts_with($haystack, $needle);
                },
            );

        MockHelper::$time = time();
        $cache->set('expired', 'value', 1);
        $expiredFile = $this->findCacheFile(self::CACHE_DIRECTORY, 'expired.bin');
        MockHelper::$time += 2;
        clearstatcache();

        $this->collectGarbage($cache);

        $this->assertTrue($raced, 'The race was not simulated.');
        $this->assertFalse($cache->has('expired'));
    }

    #[RunInSeparateProcess]
    public function testClearIgnoresItemAddedByAnotherProcess(): void
    {
        $cache = new FileCache(self::CACHE_DIRECTORY, gcProbability: 0);
        $armed = false;
        $raced = false;

        $this
            ->getFunctionMock(self::MOCK_NAMESPACE, 'str_starts_with')
            ->expects($this->any())
            ->willReturnCallback(
                static function (string $haystack, string $needle) use (&$armed, &$raced): bool {
                    if ($armed && $haystack === 'ab1.bin') {
                        $armed = false;
                        $raced = (new FileCache(self::CACHE_DIRECTORY, gcProbability: 0))->set('ab2', 'value');
                    }
                    return str_starts_with($haystack, $needle);
                },
            );

        // Both items are stored in the same subdirectory.
        $cache->set('ab1', 'value');
        $armed = true;

        $this->assertTrue($cache->clear());
        $this->assertTrue($raced, 'The race was not simulated.');
        $this->assertFalse($cache->has('ab1'));
    }

    #[RunInSeparateProcess]
    public function testFailedSetLeavesNoTemporaryFile(): void
    {
        $cache = new FileCache(self::CACHE_DIRECTORY);

        $this
            ->getFunctionMock(self::MOCK_NAMESPACE, 'file_put_contents')
            ->expects($this->any())
            ->willReturnCallback(
                static function (string $filename): bool {
                    file_put_contents($filename, 'partial');
                    return false;
                },
            );

        $this->assertFalse($cache->set('key', 'value'));
        $this->assertSame([], glob(self::CACHE_DIRECTORY . '/*/*'));
    }

    #[RunInSeparateProcess]
    public function testSetRecreatesDirectoryRemovedByAnotherProcess(): void
    {
        $cache = new FileCache(self::CACHE_DIRECTORY);
        $raced = false;

        $this
            ->getFunctionMock(self::MOCK_NAMESPACE, 'file_put_contents')
            ->expects($this->any())
            ->willReturnCallback(
                function (string $filename, mixed $data) use (&$raced): int|false {
                    if (!$raced) {
                        $raced = true;
                        $this->removeDirectory(self::CACHE_DIRECTORY);
                    }
                    return file_put_contents($filename, $data);
                },
            );

        $this->assertTrue($cache->set('key', 'value'));
        $this->assertTrue($raced, 'The race was not simulated.');
        $this->assertSame('value', $cache->get('key'));
    }
}
