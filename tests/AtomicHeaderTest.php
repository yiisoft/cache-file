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

    public function testReadsLegacyFile(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $cache->set('key', 'fixture');
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
        file_put_contents($file, serialize(['legacy' => true]));
        touch($file, time() + 3600);

        $value = $cache->get('key');

        $this->assertSame(['legacy' => true], $value);
        $this->assertTrue($cache->has('key'));
    }

    public function testReplacesLegacyFileWithNewFormat(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $cache->set('key', 'fixture');
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
        file_put_contents($file, serialize(['legacy' => true]));
        touch($file, time() + 3600);

        $result = $cache->set('key', false);

        $this->assertTrue($result);
        $this->assertStringStartsWith('YFC1 ', file_get_contents($file));
        $this->assertFalse($cache->get('key', 'miss'));
        $this->assertTrue($cache->has('key'));
    }

    public function testExpiredLegacyFileIsCollected(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $cache->set('key', 'fixture');
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
        file_put_contents($file, serialize('old'));
        touch($file, time() - 3600);
        $this->assertSame('miss', $cache->get('key', 'miss'));
        $this->collectGarbage($cache);
        $this->assertFileDoesNotExist($file);
    }

    #[DataProvider('truncatedPayloadSizes')]
    public function testTruncatedPayloadIsAMiss(int $size): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $this->assertTrue($cache->set('key', ['value' => str_repeat('x', $size)]));
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
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
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
        touch($file, time() - 3600);

        $value = $cache->get('key');

        $this->assertSame('value', $value);
    }

    public function testGcKeepsLiveEntryWithPastMtime(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $cache->set('key', 'value', 3600);
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
        touch($file, time() - 3600);

        $this->collectGarbage($cache);

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
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
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

    #[DataProvider('malformedHeaders')]
    public function testMalformedHeaderIsAMiss(string $header): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        $cache->set('key', 'fixture');
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
        file_put_contents($file, $header);
        $this->assertSame('miss', $cache->get('key', 'miss'));
        $this->assertFalse($cache->has('key'));
    }

    public static function malformedHeaders(): array
    {
        return [
            'invalid syntax' => ['YFC1 invalid header'],
            'empty payload' => ["YFC1 4102444800 0\n"],
        ];
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
        $cache->set('key', 'value', 60);
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
        touch($file, time() + 86400);
        MockHelper::$time += 120;

        $value = $cache->get('key', 'miss');

        $this->assertSame('miss', $value);
        $this->assertFalse($cache->has('key'));
    }

    public function testGcRemovesExpiredEntryWithFutureMtime(): void
    {
        $cache = new FileCache(self::DIRECTORY, gcProbability: 0);
        MockHelper::$time = time();
        $cache->set('key', 'value', 60);
        $file = $this->findCacheFile(self::DIRECTORY, 'key.bin');
        touch($file, time() + 86400);
        MockHelper::$time += 120;

        $this->collectGarbage($cache);

        $this->assertFileDoesNotExist($file);
    }
}
