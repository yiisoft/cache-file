<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Tests;

use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Cache\File\MockHelper;

require_once __DIR__ . '/MockHelper.php';

final class GarbageCollectionFailureTest extends TestCase
{
    use PHPMock;

    private const DIRECTORY = __DIR__ . '/runtime/gc-failure';

    protected function tearDown(): void
    {
        MockHelper::$randomInt = null;
        foreach (glob(self::DIRECTORY . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir(self::DIRECTORY)) {
            rmdir(self::DIRECTORY);
        }
    }

    #[RunInSeparateProcess]
    public function testGcKeepsEntryWhenModificationTimeCannotBeRead(): void
    {
        $cache = new FileCache(self::DIRECTORY, directoryLevel: 0, gcProbability: 0);
        $cache->set('key', 'live', 3600);
        $this->getFunctionMock('Yiisoft\Cache\File', 'filemtime')
            ->expects($this->once())
            ->willReturn(false);
        MockHelper::$randomInt = 0;

        $cache->withGcProbability(1_000_000)->set('gc-trigger', null, 0);

        $this->assertFileExists(self::DIRECTORY . '/key.bin');
    }

    public function testGcRemovesExpiredEntries(): void
    {
        $cache = new FileCache(self::DIRECTORY, directoryLevel: 0, gcProbability: 0);
        $cache->set('expired', 'old');
        touch(self::DIRECTORY . '/expired.bin', time() - 1);
        $cache->set('live', 'fresh', 3600);
        MockHelper::$randomInt = 0;

        $cache->withGcProbability(1_000_000)->set('gc-trigger', null, 0);

        $this->assertFileDoesNotExist(self::DIRECTORY . '/expired.bin');
        $this->assertSame('fresh', $cache->get('live'));
    }
}
