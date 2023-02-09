<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Tests;

use Yiisoft\Aliases\Aliases;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

final class ConfigTest extends TestCase
{
    public function testBase(): void
    {
        $container = $this->createContainer();

        $fileCache = $container->get(FileCache::class);

        $this->assertInstanceOf(FileCache::class, $fileCache);
    }

    private function createContainer(): Container
    {
        return new Container(
            ContainerConfig::create()->withDefinitions(
                $this->getDiConfig()
                +
                [
                    Aliases::class => [
                        '__construct()' => [
                            [
                                '@runtime' => __DIR__ . '/environment',
                            ],
                        ],
                    ],
                ]
            )
        );
    }

    private function getDiConfig(): array
    {
        $params = $this->getParams();
        return require dirname(__DIR__) . '/config/di.php';
    }

    private function getParams(): array
    {
        return require dirname(__DIR__) . '/config/params.php';
    }
}
