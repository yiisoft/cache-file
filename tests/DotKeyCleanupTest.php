<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Cache\File\MockHelper;

final class DotKeyCleanupTest extends TestCase
{
    private const DIRECTORY = __DIR__ . '/runtime/dot-key';

    protected function tearDown(): void
    {
        MockHelper::$time = null;
        $this->removeDirectory(self::DIRECTORY);
    }

    public static function cleanupScenarios(): array
    {
        $cases = [];
        foreach ([0, 1, 2] as $level) {
            foreach (['clear' => false, 'gc' => true] as $operation => $expiredOnly) {
                foreach (['.bin', '.cache'] as $suffix) {
                    $cases["$operation-level$level-$suffix"] = [$level, $expiredOnly, $suffix];
                }
            }
        }
        return $cases;
    }

    #[DataProvider('cleanupScenarios')]
    public function testCleanupRemovesDotKeyAndKeepsUnrelatedFiles(int $level, bool $expiredOnly, string $suffix): void
    {
        $cache = new FileCache(self::DIRECTORY, fileSuffix: $suffix, directoryLevel: $level, gcProbability: 0);
        MockHelper::$time = time();
        $cache->set('.foo', 'cached', 1);
        $file = $this->findCacheFile(self::DIRECTORY, '.foo' . $suffix);
        file_put_contents(self::DIRECTORY . '/.gitignore', '*');
        touch(self::DIRECTORY . '/.gitignore', time() - 3600);
        $paths = [self::DIRECTORY . '/.git/objects', self::DIRECTORY . '/ab/.config'];
        $paths[] = self::DIRECTORY . str_repeat('/ab', $level) . '/.x';
        foreach ($paths as $path) {
            mkdir($path, recursive: true);
            file_put_contents($path . '/keep', 'unrelated');
            touch($path . '/keep', time() - 3600);
        }
        MockHelper::$time += 2;

        if ($expiredOnly) {
            $this->collectGarbage($cache);
        } else {
            $cache->clear();
        }

        $this->assertFileDoesNotExist($file);
        $this->assertSame('*', file_get_contents(self::DIRECTORY . '/.gitignore'));
        foreach ($paths as $path) {
            $this->assertSame('unrelated', file_get_contents($path . '/keep'));
        }
    }

    public function testEmptySuffixDoesNotIdentifyUnrelatedHiddenFilesAsCacheEntries(): void
    {
        $cache = new FileCache(self::DIRECTORY, fileSuffix: '', directoryLevel: 0, gcProbability: 0);
        $cache->set('key', 'value');
        file_put_contents(self::DIRECTORY . '/.gitignore', '*');

        $cache->clear();

        $this->assertSame('*', file_get_contents(self::DIRECTORY . '/.gitignore'));
    }
}
