<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Benchmarks;

use Generator;
use PhpBench\Attributes as Bench;
use ReflectionMethod;
use RuntimeException;
use Yiisoft\Cache\File\FileCache;

use function array_slice;

#[Bench\AfterMethods('tearDown')]
#[Bench\Revs(1)]
#[Bench\Warmup(0)]
#[Bench\Iterations(7)]
final class MaintenanceBench extends CacheFixture
{
    public function tearDown(): void
    {
        try {
            // A cache miss alone does not prove that expired files were collected.
            $getFile = new ReflectionMethod(FileCache::class, 'getCacheFile');
            clearstatcache();
            foreach ($this->expectedValues as $key => $value) {
                if (is_file($getFile->invoke($this->cache, $key)) !== ($value !== null)) {
                    throw new RuntimeException('Maintenance left an unexpected set of files.');
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function provideSizes(): Generator
    {
        yield '100-items' => ['count' => 100, 'level' => 1];
        yield '1000-items' => ['count' => 1000, 'level' => 1];
    }

    public function provideGc(): Generator
    {
        foreach ($this->provideSizes() as $name => $params) {
            foreach ([0, 50, 100] as $expired) {
                yield $name . '-' . $expired . '%-expired' => $params + ['expired' => $expired];
            }
        }
    }

    public function setUpGc(array $params): void
    {
        $this->setUpPopulated($params);
        $this->expire(array_slice($this->keys, 0, (int) ($params['count'] * $params['expired'] / 100)));
        // Benchmark the sweep directly: the public set() trigger is probabilistic,
        // even at gcProbability=1_000_000 (random_int includes both endpoints).
        $this->collect = (new ReflectionMethod(FileCache::class, 'removeCacheFiles'))->getClosure($this->cache);
    }

    #[Bench\ParamProviders('provideSizes')]
    #[Bench\BeforeMethods('setUpPopulated')]
    public function benchClear(): void
    {
        $this->start();
        $this->results = [$this->cache->clear()];
        $this->expectedResults = [true];
        $this->expectedValues = array_fill_keys($this->keys, null);
    }

    #[Bench\ParamProviders('provideGc')]
    #[Bench\BeforeMethods('setUpGc')]
    public function benchGarbageCollection(): void
    {
        $this->start();
        ($this->collect)($this->path, true);
    }
}
