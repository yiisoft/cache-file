<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Tests;

require_once __DIR__ . '/MockHelper.php';

use ArrayIterator;
use DateInterval;
use Exception;
use IteratorAggregate;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;
use Yiisoft\Cache\File\CacheException;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Cache\File\MockHelper;

use function array_keys;
use function array_map;
use function dirname;
use function fileperms;
use function file_put_contents;
use function function_exists;
use function glob;
use function is_dir;
use function is_object;
use function posix_geteuid;
use function rmdir;
use function sprintf;
use function str_replace;
use function substr;
use function sys_get_temp_dir;
use function time;
use function uniqid;
use function unlink;

final class FileCacheTest extends TestCase
{
    use PHPMock;

    private const RUNTIME_DIRECTORY = __DIR__ . '/runtime';

    private FileCache $cache;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->cache = new FileCache(self::RUNTIME_DIRECTORY . '/cache');
        $this->tmpDir = sys_get_temp_dir() . '/yiisoft-test-file-cache';
    }

    protected function tearDown(): void
    {
        MockHelper::$time = null;
        $this->removeDirectory($this->tmpDir);
        $this->removeDirectory(self::RUNTIME_DIRECTORY);
    }

    #[DataProvider('dataProvider')]
    public function testSet($key, $value): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->assertTrue($this->cache->set($key, $value));
        }
    }

    #[DataProvider('dataProvider')]
    public function testGet($key, $value): void
    {
        $this->cache->set($key, $value);
        $valueFromCache = $this->cache->get($key, 'default');

        $this->assertSameExceptObject($value, $valueFromCache);
    }

    #[DataProvider('dataProvider')]
    public function testValueInCacheCannotBeChanged($key, $value): void
    {
        $this->cache->set($key, $value);
        $valueFromCache = $this->cache->get($key, 'default');

        $this->assertSameExceptObject($value, $valueFromCache);

        if (is_object($value)) {
            $originalValue = clone $value;
            $valueFromCache->test_field = 'changed';
            $value->test_field = 'changed';
            $valueFromCacheNew = $this->cache->get($key, 'default');
            $this->assertSameExceptObject($originalValue, $valueFromCacheNew);
        }
    }

    #[DataProvider('dataProvider')]
    public function testHas($key, $value): void
    {
        $this->cache->set($key, $value);

        $this->assertTrue($this->cache->has($key));
        // check whether exists affects the value
        $this->assertSameExceptObject($value, $this->cache->get($key));

        $this->assertTrue($this->cache->has($key));
        $this->assertFalse($this->cache->has('not_exists'));
    }

    public function testGetNonExistent(): void
    {
        $this->assertNull($this->cache->get('non_existent_key'));
    }

    #[DataProvider('dataProvider')]
    public function testDelete($key, $value): void
    {
        $this->cache->set($key, $value);

        $this->assertSameExceptObject($value, $this->cache->get($key));
        $this->assertTrue($this->cache->delete($key));
        $this->assertNull($this->cache->get($key));
    }

    #[DataProvider('dataProvider')]
    public function testClear($key, $value): void
    {
        $cache = $this->prepare($this->cache);

        $this->assertTrue($cache->clear());
        $this->assertNull($cache->get($key));
    }

    #[DataProvider('dataProviderSetMultiple')]
    public function testSetMultiple(?int $ttl): void
    {
        $data = $this->getDataProviderData();
        $this->cache->setMultiple($data, $ttl);

        foreach ($data as $key => $value) {
            $this->assertSameExceptObject($value, $this->cache->get((string) $key));
        }
    }

    /**
     * @return array testing multiSet with and without expiry
     */
    public static function dataProviderSetMultiple(): array
    {
        return [
            [null],
            [2],
        ];
    }

    public function testGetMultiple(): void
    {
        $data = $this->getDataProviderData();
        $keys = array_map('\strval', array_keys($data));
        $this->cache->setMultiple($data);

        $this->assertSameExceptObject($data, $this->cache->getMultiple($keys));
    }

    public function testDeleteMultiple(): void
    {
        $data = $this->getDataProviderData();
        $keys = array_map('\strval', array_keys($data));
        $this->cache->setMultiple($data);

        $this->assertSameExceptObject($data, $this->cache->getMultiple($keys));

        $this->cache->deleteMultiple($keys);
        $emptyData = array_map(static fn () => null, $data);

        $this->assertSameExceptObject($emptyData, $this->cache->getMultiple($keys));
    }

    public function testZeroAndNegativeTtl(): void
    {
        $this->cache->setMultiple(['a' => 1, 'b' => 2]);

        $this->assertTrue($this->cache->has('a'));
        $this->assertTrue($this->cache->has('b'));

        $this->cache->set('a', 11, -1);
        $this->assertFalse($this->cache->has('a'));

        $this->cache->set('b', 22, 0);
        $this->assertFalse($this->cache->has('b'));
    }

    #[DataProvider('dataProviderNormalizeTtl')]
    public function testNormalizeTtl(mixed $ttl, mixed $expectedResult): void
    {
        $this->assertSameExceptObject($expectedResult, $this->invokeMethod($this->cache, 'normalizeTtl', [$ttl]));
    }

    /**
     * Data provider for {@see testNormalizeTtl()}
     *
     * @return array test data
     */
    public static function dataProviderNormalizeTtl(): array
    {
        return [
            [123, 123],
            ['123', 123],
            [null, null],
            [0, 0],
            [new DateInterval('PT6H8M'), 6 * 3600 + 8 * 60],
            [new DateInterval('P2Y4D'), 2 * 365 * 24 * 3600 + 4 * 24 * 3600],
        ];
    }

    #[DataProvider('ttlToExpirationProvider')]
    public function testTtlToExpiration(mixed $ttl, mixed $expected): void
    {
        if ($expected === 'calculate_expiration') {
            MockHelper::$time = time();
            $expected = MockHelper::$time + $ttl;
        }

        if ($expected === 'calculate_max_expiration') {
            MockHelper::$time = time();
            $expected = MockHelper::$time + 31_536_000;
        }

        $this->assertSameExceptObject($expected, $this->invokeMethod($this->cache, 'ttlToExpiration', [$ttl]));
    }

    public static function ttlToExpirationProvider(): array
    {
        return [
            [3, 'calculate_expiration'],
            [null, 'calculate_max_expiration'],
            [-5, -1],
            ['', -1],
            [0, -1],
        ];
    }

    #[DataProvider('iterableProvider')]
    public function testValuesAsIterable(array $array, iterable $iterable): void
    {
        $this->cache->setMultiple($iterable);

        $this->assertSameExceptObject($array, $this->cache->getMultiple(array_keys($array)));
    }

    public static function iterableProvider(): array
    {
        return [
            'array' => [
                ['a' => 1, 'b' => 2,],
                ['a' => 1, 'b' => 2,],
            ],
            'ArrayIterator' => [
                ['a' => 1, 'b' => 2,],
                new ArrayIterator(['a' => 1, 'b' => 2,]),
            ],
            'IteratorAggregate' => [
                ['a' => 1, 'b' => 2,],
                new class () implements IteratorAggregate {
                    public function getIterator(): ArrayIterator
                    {
                        return new ArrayIterator(['a' => 1, 'b' => 2,]);
                    }
                },
            ],
            'generator' => [
                ['a' => 1, 'b' => 2,],
                (static function () {
                    yield 'a' => 1;
                    yield 'b' => 2;
                })(),
            ],
        ];
    }

    public function testExpire(): void
    {
        MockHelper::$time = time();
        $this->assertTrue($this->cache->set('expire_test', 'expire_test', 2));
        MockHelper::$time++;
        $this->assertEquals('expire_test', $this->cache->get('expire_test'));
        MockHelper::$time++;
        $this->assertNull($this->cache->get('expire_test'));
    }

    /**
     * We have to on separate process because of PHPMock not being able to mock a function that
     * was already called.
     *
     * runInSeparateProcess
     */
    public function testCacheRenewalOnDifferentOwnership(): void
    {
        if (!function_exists('posix_geteuid')) {
            $this->markTestSkipped('Can not test without posix extension installed.');
        }

        $cacheValue = uniqid('value_', false);
        $cacheKey = uniqid('key_', false);

        MockHelper::$time = time();
        $this->assertTrue($this->cache->set($cacheKey, $cacheValue, 2));
        $this->assertSame($cacheValue, $this->cache->get($cacheKey));

        // Override fileowner method so it always returns something not equal to the current user
        $notCurrentEuid = posix_geteuid() + 15;
        $this
            ->getFunctionMock('Yiisoft\Cache\File', 'fileowner')
            ->expects($this->any())
            ->willReturn($notCurrentEuid);

        $this->assertTrue(
            $this->cache->set($cacheKey, uniqid('value_2_', false), 2),
            'Cannot rebuild cache on different file ownership',
        );
    }

    public function testSetWithDateIntervalTtl(): void
    {
        $this->cache->set('a', 1, new DateInterval('PT1H'));
        $this->assertSameExceptObject(1, $this->cache->get('a'));

        $this->cache->setMultiple(['b' => 2]);
        $this->assertSameExceptObject(['b' => 2], $this->cache->getMultiple(['b']));
    }

    public function testFileSuffix(): void
    {
        $cache = $this->cache->withFileSuffix('.test');

        $this->assertInstanceOf(FileCache::class, $cache);
        $this->assertNotSame($this->cache, $cache);

        $cache->set('a', 1);
        $this->assertSameExceptObject(1, $cache->get('a'));

        $cacheFile = $this->invokeMethod($cache, 'getCacheFile', ['a']);
        $this->assertEquals('.test', substr($cacheFile, -5));
    }

    public function testFileMode(): void
    {
        if ($this->isWindows()) {
            $this->markTestSkipped('Can not test permissions on Windows');
        }

        $cache = new FileCache($this->tmpDir);
        $newCache = $cache->withFileMode(0755);

        $this->assertInstanceOf(FileCache::class, $newCache);
        $this->assertNotSame($cache, $newCache);

        $newCache->set('a', 1);
        $this->assertSameExceptObject(1, $newCache->get('a'));

        $cacheFile = $this->invokeMethod($newCache, 'getCacheFile', ['a']);
        $permissions = substr(sprintf('%o', fileperms($cacheFile)), -4);

        $this->assertEquals('0755', $permissions);
    }

    public function testDirectoryModeDeprecated(): void
    {
        if ($this->isWindows()) {
            $this->markTestSkipped('Can not test permissions on Windows');
        }

        $cache = new FileCache($this->tmpDir);
        $newCache = $cache->withDirectoryMode(0777);

        $this->assertInstanceOf(FileCache::class, $newCache);
        $this->assertNotSame($cache, $newCache);

        $newCache->set('a', 1);
        $this->assertSameExceptObject(1, $newCache->get('a'));

        $cacheFile = $this->invokeMethod($newCache, 'getCacheFile', ['a']);
        $permissions = substr(sprintf('%o', fileperms(dirname($cacheFile))), -4);

        $this->assertEquals('0777', $permissions);
    }

    public function testDirectoryMode(): void
    {
        if ($this->isWindows()) {
            $this->markTestSkipped('Can not test permissions on Windows');
        }

        $cache = (new FileCache($this->tmpDir, 0777))->withDirectoryLevel(2);

        $this->assertInstanceOf(FileCache::class, $cache);

        $cache->set('test', 1);
        $this->assertSameExceptObject(1, $cache->get('test'));

        $cacheFile = $this->invokeMethod($cache, 'getCacheFile', ['test']);
        $this->assertEquals('0777', substr(sprintf('%o', fileperms(dirname($cacheFile))), -4));
        $this->assertEquals('0777', substr(sprintf('%o', fileperms(dirname($cacheFile, 2))), -4));

        // also check top level cache dir permissions
        $permissions = substr(sprintf('%o', fileperms($this->tmpDir)), -4);
        $this->assertEquals('0777', $permissions);
    }

    public function testDirectoryLevel(): void
    {
        $cache = $this->cache->withDirectoryLevel(0);

        $this->assertInstanceOf(FileCache::class, $cache);
        $this->assertNotSame($this->cache, $cache);

        $cache->set('a', 1);
        $this->assertSameExceptObject(1, $cache->get('a'));

        $cacheFile = $this->invokeMethod($cache, 'getCacheFile', ['a']);
        $this->assertPathEquals(__DIR__ . '/runtime/cache/a.bin', $cacheFile);
    }

    #[DataProvider('nestedDirectoriesDataProvider')]
    public function testDirectoryLevelWithSubdirs(int $directoryLevel, string $key, string $expectedPath): void
    {
        $cache = $this->cache->withDirectoryLevel($directoryLevel);

        $cache->set($key, 1);

        $cacheFile = $this->invokeMethod($cache, 'getCacheFile', [$key]);
        $this->assertPathEquals(__DIR__ . "/runtime/cache/$expectedPath.bin", $cacheFile);
    }

    public static function nestedDirectoriesDataProvider(): array
    {
        return [
            'single level' => [1, '0123456789', '01/0123456789'],
            'two levels, normal key' => [2, '0123456789', '01/23/0123456789'],
            'two levels, short key' => [2, '012', '01/2/012'],
            'two levels, too short key' => [2, '0', '0/0'],
            'three levels, normal key' => [3, '0123456789', '01/23/45/0123456789'],
        ];
    }

    public function testGcProbability(): void
    {
        $cache = $this->cache->withGcProbability(1_000_000);

        $this->assertInstanceOf(FileCache::class, $cache);
        $this->assertNotSame($this->cache, $cache);

        $key = 'gc_probability_test';
        MockHelper::$time = time();

        $cache->set($key, 1, 1);
        $this->assertSameExceptObject(1, $cache->get($key));

        $cacheFile = $this->invokeMethod($cache, 'getCacheFile', [$key]);
        $this->assertFileExists($cacheFile);

        MockHelper::$time++;
        MockHelper::$time++;

        $cache->set('b', 2);
        $this->assertFileDoesNotExist($cacheFile);
    }

    public function testDeleteForCacheItemNotExist(): void
    {
        $this->assertNull($this->cache->get('key'));
        $this->assertTrue($this->cache->delete('key'));
        $this->assertNull($this->cache->get('key'));
    }

    public function testSetThrowExceptionForInvalidCacheDirectory(): void
    {
        $directory = self::RUNTIME_DIRECTORY . '/cache/fail';
        $cache = new FileCache($directory);

        mkdir(dirname($directory), recursive: true);
        file_put_contents($directory, 'fail');

        $this->expectException(CacheException::class);
        $cache->set('key', 'value');
    }

    public static function invalidKeyProvider(): array
    {
        return [
            'psr-reserved' => ['{}()/\@:'],
            'empty-string' => [''],
        ];
    }

    #[DataProvider('invalidKeyProvider')]
    public function testGetThrowExceptionForInvalidKey(mixed $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->get($key);
    }

    #[DataProvider('invalidKeyProvider')]
    public function testSetThrowExceptionForInvalidKey(mixed $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->set($key, 'value');
    }

    #[DataProvider('invalidKeyProvider')]
    public function testDeleteThrowExceptionForInvalidKey(mixed $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->delete($key);
    }

    #[DataProvider('invalidKeyProvider')]
    public function testGetMultipleThrowExceptionForInvalidKeys(mixed $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->getMultiple([$key]);
    }

    #[DataProvider('invalidKeyProvider')]
    public function testDeleteMultipleThrowExceptionForInvalidKeys(mixed $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->deleteMultiple([$key]);
    }

    #[DataProvider('invalidKeyProvider')]
    public function testHasThrowExceptionForInvalidKey(mixed $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->has($key);
    }

    private function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    private function assertPathEquals($expected, $actual, string $message = ''): void
    {
        $expected = $this->normalizePath($expected);
        $actual = $this->normalizePath($actual);
        $this->assertSame($expected, $actual, $message);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        if ($items = glob("{$directory}/*")) {
            foreach ($items as $item) {
                is_dir($item) ? $this->removeDirectory($item) : unlink($item);
            }
        }

        rmdir($directory);
    }

    public function testSetClearsStatCache(): void
    {
        $this->cache->set(__FUNCTION__, 'cache1', 2);

        $refClass = new \ReflectionClass($this->cache);
        $refMethodGetCacheFile = $refClass->getMethod('getCacheFile');
        $refMethodGetCacheFile->setAccessible(true);
        $cacheFile = $refMethodGetCacheFile->invoke($this->cache, __FUNCTION__);

        // simulate cache expire 10 seconds ago
        touch($cacheFile, time() - 10);
        clearstatcache();

        $this->assertNull($this->cache->get(__FUNCTION__));
        $this->assertTrue($this->cache->set(__FUNCTION__, 'cache2', 2));
        $this->assertSame('cache2', $this->cache->get(__FUNCTION__));
    }

    public function testMkdirConvertingErrorToException(): void
    {
        $cache = new FileCache('');

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Failed to create directory "". mkdir(): Invalid path');
        $cache->set('test', 0);
    }
}
