<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use Yiisoft\Cache\File\FileCache;
use Exception;
use phpmock\phpunit\PHPMock;
use Yiisoft\Cache\File\MockHelper;

use function dirname;
use function is_array;

use const DIRECTORY_SEPARATOR;

require_once __DIR__ . '/MockHelper.php';

final class AtomicHeaderTest extends TestCase
{
    use PHPMock;
    private const DIRECTORY = __DIR__ . '/runtime/atomic-header';

    protected function tearDown(): void
    {
        MockHelper::$time = null;
        $this->removeDirectory(self::DIRECTORY);
    }

    public function testReadsLegacyFilesAndRewritesNewFormat(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $file = $this->invokeMethod($cache, 'getCacheFile', ['key', true]);
        file_put_contents($file, serialize(['legacy' => true]));
        touch($file, time() + 3600);
        $this->assertSame(['legacy' => true], $cache->get('key'));
        $this->assertTrue($cache->has('key'));
        $this->assertTrue($cache->set('key', false));
        $this->assertStringStartsWith('YFC1 ', file_get_contents($file));
        $this->assertFalse($cache->get('key', 'miss'));
        $this->assertTrue($cache->has('key'));
    }

    public function testExpiredLegacyFileIsCollected(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $file = $this->invokeMethod($cache, 'getCacheFile', ['key', true]);
        file_put_contents($file, serialize('old'));
        touch($file, time() - 3600);
        $this->assertSame('miss', $cache->get('key', 'miss'));
        $this->invokeMethod($cache, 'removeCacheFiles', [self::DIRECTORY, true]);
        $this->assertFileDoesNotExist($file);
    }

    #[DataProvider('truncatedPayloadSizes')]
    public function testTruncatedPayloadIsAMiss(int $size): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $this->assertTrue($cache->set('key', ['value' => str_repeat('x', $size)]));
        $file = $this->invokeMethod($cache, 'getCacheFile', ['key']);
        file_put_contents($file, substr(file_get_contents($file), 0, -7));
        $this->assertSame('miss', $cache->get('key', 'miss'));
        $this->assertFalse($cache->has('key'));
    }

    public static function truncatedPayloadSizes(): array
    {
        return [[100], [4096], [65536]];
    }

    public function testExpirationDoesNotDependOnMtime(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $cache->set('key', 'value', 3600);
        $file = $this->invokeMethod($cache, 'getCacheFile', ['key']);
        touch($file, time() - 3600);
        $this->assertSame('value', $cache->get('key'));
        $this->invokeMethod($cache, 'removeCacheFiles', [self::DIRECTORY, true]);
        $this->assertSame('value', $cache->get('key'));
    }

    public function testSerializationFailurePreservesValue(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $cache->set('key', 'old');
        $value = static fn() => null;
        try {
            $cache->set('key', $value);
            $this->fail('Serialization must throw.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Serialization of', $e->getMessage());
        }
        $this->assertSame('old', $cache->get('key'));
    }

    #[RunInSeparateProcess]
    public function testShortWritePreservesOldValueAndCleansTemporaryFile(): void
    {
        $armed = false;
        $this->getFunctionMock('Yiisoft\Cache\File', 'file_put_contents')->expects($this->atLeastOnce())
            ->willReturnCallback(static function (string $path, string|array $data) use (&$armed): int|false {
                $data = is_array($data) ? implode('', $data) : $data;
                return file_put_contents($path, $armed ? substr($data, 0, -1) : $data);
            });
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $this->assertTrue($cache->set('key', 'old'));
        $armed = true;
        $this->assertFalse($cache->set('key', 'new'));
        $this->assertSame('old', $cache->get('key'));
        $file = $this->invokeMethod($cache, 'getCacheFile', ['key']);
        $this->assertSame([$file], glob(dirname($file) . DIRECTORY_SEPARATOR . '*'));
    }

    #[RunInSeparateProcess]
    #[RequiresOperatingSystem('^(?!WIN)')]
    public function testOpenedReaderKeepsMatchingHeaderAndPayloadAcrossReplacement(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $armed = false;
        $this->getFunctionMock('Yiisoft\Cache\File', 'fgets')->expects($this->atLeastOnce())
            ->willReturnCallback(static function ($handle, int $length) use ($cache, &$armed): string|false {
                $header = fgets($handle, $length);
                if ($armed) {
                    $armed = false;
                    $cache->set('key', 'replacement with a different length', 60);
                }
                return $header;
            });
        $this->assertTrue($cache->set('key', 'old', 3600));
        $armed = true;
        $this->assertSame('old', $cache->get('key'));
        $this->assertSame('replacement with a different length', $cache->get('key'));
    }

    #[RunInSeparateProcess]
    public function testNewFormatReadDoesNotCallTouchOrExplicitStatFunctions(): void
    {
        foreach (['touch', 'stat', 'fstat', 'filemtime', 'filesize'] as $function) {
            $this->getFunctionMock('Yiisoft\Cache\File', $function)->expects($this->never());
        }
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $this->assertTrue($cache->set('key', 'value', 3600));
        $this->assertSame('value', $cache->get('key'));
    }

    public function testMalformedHeaderIsAMiss(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $file = $this->invokeMethod($cache, 'getCacheFile', ['key', true]);
        file_put_contents($file, 'YFC1 invalid header');
        $this->assertSame('miss', $cache->get('key', 'miss'));
        $this->assertFalse($cache->has('key'));
    }

    public function testDirectoryAtCachePathIsAMiss(): void
    {
        $cache = new FileCache(self::DIRECTORY, fileSuffix: '', directoryLevel: 0, gcProbability: 0);
        mkdir(self::DIRECTORY . '/key', recursive: true);
        $this->assertSame('miss', $cache->get('key', 'miss'));
        $this->assertFalse($cache->has('key'));
    }

    public function testExpiredHeaderWinsOverFutureMtime(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        MockHelper::$time = time();
        $this->assertTrue($cache->set('key', 'value', 60));
        $file = $this->invokeMethod($cache, 'getCacheFile', ['key']);
        touch($file, time() + 86400);
        MockHelper::$time += 120;
        $this->assertSame('miss', $cache->get('key', 'miss'));
        $this->assertFalse($cache->has('key'));
        $this->invokeMethod($cache, 'removeCacheFiles', [self::DIRECTORY, true]);
        $this->assertFileDoesNotExist($file);
    }
}
