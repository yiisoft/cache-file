<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Benchmarks;

use PhpBench\Attributes as Bench;

#[Bench\ParamProviders('provideValues')]
#[Bench\AfterMethods('tearDown')]
#[Bench\Revs(1)]
#[Bench\Warmup(0)]
#[Bench\Iterations(7)]
final class OperationsBench extends CacheFixture
{
    #[Bench\BeforeMethods('setUpPopulated')]
    public function benchGetHit(): void
    {
        $this->start();
        foreach ($this->keys as $key) {
            $this->results[$key] = $this->cache->get($key);
        }
        $this->expectedResults = $this->values;
    }

    #[Bench\BeforeMethods('setUpPopulated')]
    public function benchGetMiss(): void
    {
        $this->start();
        foreach ($this->missingKeys as $key) {
            $this->results[] = $this->cache->get($key);
        }
        $this->expectedResults = array_fill(0, 100, null);
    }

    #[Bench\BeforeMethods('setUpExpired')]
    public function benchGetExpired(): void
    {
        $this->start();
        foreach ($this->keys as $key) {
            $this->results[] = $this->cache->get($key);
        }
        $this->expectedResults = array_fill(0, 100, null);
    }

    #[Bench\BeforeMethods('setUpPopulated')]
    public function benchHasHit(): void
    {
        $this->start();
        foreach ($this->keys as $key) {
            $this->results[] = $this->cache->has($key);
        }
        $this->expectedResults = array_fill(0, 100, true);
    }

    #[Bench\BeforeMethods('setUpPopulated')]
    public function benchHasMiss(): void
    {
        $this->start();
        foreach ($this->missingKeys as $key) {
            $this->results[] = $this->cache->has($key);
        }
        $this->expectedResults = array_fill(0, 100, false);
    }

    #[Bench\BeforeMethods('setUpExpired')]
    public function benchHasExpired(): void
    {
        $this->start();
        foreach ($this->keys as $key) {
            $this->results[] = $this->cache->has($key);
        }
        $this->expectedResults = array_fill(0, 100, false);
    }

    #[Bench\BeforeMethods('setUpDirectories')]
    public function benchSetNew(): void
    {
        $this->write();
    }

    #[Bench\BeforeMethods('setUpEmpty')]
    public function benchSetNewWithDirectories(): void
    {
        $this->write();
    }

    #[Bench\BeforeMethods('setUpOverwrite')]
    public function benchSetOverwrite(): void
    {
        $this->write();
    }

    #[Bench\BeforeMethods('setUpPopulated')]
    public function benchGetMultiple(): void
    {
        $this->start();
        foreach ($this->cache->getMultiple($this->keys) as $key => $value) {
            $this->results[$key] = $value;
        }
        $this->expectedResults = $this->values;
    }

    #[Bench\BeforeMethods('setUpDirectories')]
    public function benchSetMultipleNew(): void
    {
        $this->start();
        $this->results = [$this->cache->setMultiple($this->values, 86400)];
        $this->expectedResults = [true];
        $this->expectedValues = $this->values;
    }

    #[Bench\BeforeMethods('setUpPopulated')]
    public function benchDeleteHit(): void
    {
        $this->start();
        foreach ($this->keys as $key) {
            $this->results[] = $this->cache->delete($key);
        }
        $this->expectedResults = array_fill(0, 100, true);
        $this->expectedValues = array_fill_keys($this->keys, null);
    }

    #[Bench\BeforeMethods('setUpPopulated')]
    public function benchDeleteMiss(): void
    {
        $this->start();
        foreach ($this->missingKeys as $key) {
            $this->results[] = $this->cache->delete($key);
        }
        $this->expectedResults = array_fill(0, 100, true);
    }

    #[Bench\BeforeMethods('setUpPopulated')]
    public function benchDeleteMultiple(): void
    {
        $this->start();
        $this->results = [$this->cache->deleteMultiple($this->keys)];
        $this->expectedResults = [true];
        $this->expectedValues = array_fill_keys($this->keys, null);
    }

    #[Bench\BeforeMethods('setUpPopulated')]
    public function benchSetExpiredTtl(): void
    {
        $this->start();
        foreach ($this->keys as $key) {
            $this->results[] = $this->cache->set($key, $this->value, 0);
        }
        $this->expectedResults = array_fill(0, 100, true);
        $this->expectedValues = array_fill_keys($this->keys, null);
    }

    #[Bench\BeforeMethods('setUpOverwrite')]
    public function benchSetMultipleOverwrite(): void
    {
        $this->start();
        $this->results = [$this->cache->setMultiple($this->values, 86400)];
        $this->expectedResults = [true];
        $this->expectedValues = $this->values;
    }

    private function write(): void
    {
        $this->start();
        foreach ($this->values as $key => $value) {
            $this->results[] = $this->cache->set($key, $value, 86400);
        }
        $this->expectedResults = array_fill(0, 100, true);
        $this->expectedValues = $this->values;
    }
}
