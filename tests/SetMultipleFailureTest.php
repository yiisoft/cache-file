<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Tests;

use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Yiisoft\Cache\File\FileCache;

final class SetMultipleFailureTest extends TestCase
{
    use PHPMock;

    private const DIRECTORY = __DIR__ . '/runtime/set-multiple-failure';

    protected function tearDown(): void
    {
        foreach (glob(self::DIRECTORY . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir(self::DIRECTORY)) {
            rmdir(self::DIRECTORY);
        }
    }

    #[RunInSeparateProcess]
    public function testFailureDoesNotSkipRemainingEntries(): void
    {
        $this->getFunctionMock('Yiisoft\Cache\File', 'file_put_contents')
            ->expects($this->atLeastOnce())
            ->willReturnCallback(static function (string $path, array $data): int|false {
                return str_ends_with(implode('', $data), serialize('fail')) ? false : file_put_contents($path, $data);
            });
        $cache = new FileCache(self::DIRECTORY, directoryLevel: 0, gcProbability: 0);

        $result = $cache->setMultiple(['before' => 'ok', 'bad' => 'fail', 'after' => 'ok']);

        $this->assertSame('ok', $cache->get('before'));
        $this->assertSame('ok', $cache->get('after'));
        $this->assertFalse($cache->has('bad'));
        $this->assertFalse($result);
    }
}
