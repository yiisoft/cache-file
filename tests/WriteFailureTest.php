<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Tests;

use ErrorException;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Yiisoft\Cache\File\FileCache;

final class WriteFailureTest extends TestCase
{
    use PHPMock;

    private const DIRECTORY = __DIR__ . '/runtime/write-failure';

    protected function tearDown(): void
    {
        foreach (glob(self::DIRECTORY . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        if (is_dir(self::DIRECTORY)) {
            rmdir(self::DIRECTORY);
        }
    }

    #[RunInSeparateProcess]
    public function testShortWriteReturnsFalse(): void
    {
        $this->getFunctionMock('Yiisoft\Cache\File', 'file_put_contents')
            ->expects($this->once())
            ->willReturnCallback(static function (string $path, string $data, int $flags): int|false {
                return file_put_contents($path, substr($data, 0, -1), $flags);
            });
        $cache = new FileCache(self::DIRECTORY, directoryLevel: 0, gcProbability: 0);

        $this->assertFalse($cache->set('key', 'value'));
    }

    #[RunInSeparateProcess]
    public function testWriteWarningDoesNotEscapeAsException(): void
    {
        mkdir(self::DIRECTORY . '/key.bin', recursive: true);
        $cache = new FileCache(self::DIRECTORY, directoryLevel: 0, gcProbability: 0);
        set_error_handler(static function (int $severity, string $message): bool {
            if ((error_reporting() & $severity) !== 0) {
                throw new ErrorException($message, 0, $severity);
            }
            return true;
        });
        try {
            $this->assertFalse($cache->set('key', 'value'));
        } finally {
            restore_error_handler();
        }
    }

    public static function metadataOperations(): array
    {
        return [['chmod'], ['touch']];
    }

    #[DataProvider('metadataOperations')]
    #[RunInSeparateProcess]
    public function testMetadataFailureReturnsFalse(string $operation): void
    {
        mkdir(self::DIRECTORY, recursive: true);
        $this->getFunctionMock('Yiisoft\Cache\File', $operation)
            ->expects($this->once())
            ->willReturn(false);
        $cache = new FileCache(self::DIRECTORY, fileMode: 0600, directoryLevel: 0, gcProbability: 0);
        error_clear_last();

        $this->assertFalse($cache->set('key', 'value'));
    }
}
