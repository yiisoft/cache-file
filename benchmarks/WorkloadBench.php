<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Benchmarks;

use Generator;
use PhpBench\Attributes as Bench;

#[Bench\ParamProviders('provideWorkloads')]
#[Bench\BeforeMethods('setUpWorkload')]
#[Bench\AfterMethods('tearDown')]
#[Bench\Revs(1)]
#[Bench\Warmup(0)]
#[Bench\Iterations(7)]
final class WorkloadBench extends CacheFixture
{
    public function provideWorkloads(): Generator
    {
        foreach (['1KiB', '64KiB', 'array'] as $payload) {
            foreach ([5, 20, 50] as $writes) {
                yield $payload . '-' . (100 - $writes) . 'r-' . $writes . 'w' => [
                    'payload' => $payload,
                    'level' => 1,
                    'writes' => $writes,
                ];
            }
        }
    }

    public function setUpWorkload(array $params): void
    {
        $this->setUpOverwrite($params);
        foreach ($this->keys as $i => $key) {
            if (($i * 37) % 100 < $params['writes']) {
                $this->expectedResults[] = true;
                $this->expectedValues[$key] = $this->value;
            } else {
                $this->expectedResults[] = $this->expectedValues[$key];
            }
        }
    }

    public function benchReadWriteMix(array $params): void
    {
        $this->start();
        foreach ($this->keys as $i => $key) {
            // Multiplication by a coprime permutes 0..99, giving an exact, interleaved mix.
            if (($i * 37) % 100 < $params['writes']) {
                $this->results[] = $this->cache->set($key, $this->value, 86400);
            } else {
                $this->results[] = $this->cache->get($key);
            }
        }
    }
}
