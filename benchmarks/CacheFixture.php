<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File\Benchmarks;

use Closure;
use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use RuntimeException;
use Yiisoft\Cache\File\FileCache;

use function is_string;
use function strlen;

abstract class CacheFixture
{
    protected FileCache $cache;
    protected string $path;
    protected array $keys;
    protected array $missingKeys;
    protected array $values;
    protected mixed $value;
    protected array $results = [];
    protected array $expectedResults = [];
    protected array $expectedValues = [];
    protected Closure $collect;
    private bool $started = false;

    public function tearDown(): void
    {
        try {
            if ($this->results !== $this->expectedResults) {
                throw new RuntimeException('Unexpected benchmark operation result.');
            }
            clearstatcache();
            foreach ($this->expectedValues as $key => $value) {
                if ($this->cache->get($key) !== $value) {
                    throw new RuntimeException('Unexpected final cache value for ' . $key);
                }
            }
        } finally {
            $this->cleanUp();
        }
    }

    public function provideValues(): Generator
    {
        yield '1KiB-flat' => ['payload' => '1KiB', 'level' => 0];
        yield '1KiB-level1' => ['payload' => '1KiB', 'level' => 1];
        yield '1KiB-level2' => ['payload' => '1KiB', 'level' => 2];
        yield '64KiB-level1' => ['payload' => '64KiB', 'level' => 1];
        yield 'array-level1' => ['payload' => 'array', 'level' => 1];
    }

    public function setUpEmpty(array $params): void
    {
        $root = getenv('CACHE_FILE_BENCH_DIR') ?: __DIR__ . '/runtime';
        if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
            throw new RuntimeException('Cannot create benchmark root: ' . $root);
        }
        $this->path = $root . '/sample-' . bin2hex(random_bytes(12));
        if (!mkdir($this->path)) {
            throw new RuntimeException('Cannot create benchmark sample directory.');
        }
        // Also clean up if a subject or verification fails before the after hook.
        register_shutdown_function($this->cleanUp(...));
        $this->cache = new FileCache($this->path, directoryLevel: $params['level'], gcProbability: 0);
        $this->value = match ($params['payload'] ?? '1KiB') {
            '1KiB' => str_repeat('x', 1024),
            '64KiB' => str_repeat('x', 65536),
            'array' => array_fill(0, 32, ['id' => 42, 'name' => 'cached record', 'enabled' => true]),
        };
        $this->keys = [];
        $this->missingKeys = [];
        $this->values = [];
        $this->results = [];
        $this->expectedResults = [];
        $this->expectedValues = [];
        $this->started = false;
        for ($i = 0; $i < ($params['count'] ?? 100); $i++) {
            $key = md5('cache-file-benchmark-' . $i);
            $this->keys[] = $key;
            // Same directory as a populated key: isolate a missing file from missing directories.
            $this->missingKeys[] = $key . '-missing';
            $this->values[$key] = $this->value;
        }
        clearstatcache();
    }

    public function setUpPopulated(array $params): void
    {
        $this->setUpEmpty($params);
        foreach ($this->values as $key => $value) {
            if (!$this->cache->set($key, $value, 86400) || $this->cache->get($key) !== $value) {
                throw new RuntimeException('Cannot seed benchmark fixture.');
            }
        }
        $this->expectedValues = $this->values;
        clearstatcache();
    }

    public function setUpOverwrite(array $params): void
    {
        $this->setUpPopulated($params);
        $this->value = is_string($this->value)
            ? str_repeat('y', strlen($this->value))
            : array_fill(0, 32, ['id' => 43, 'name' => 'updated entry', 'enabled' => false]);
        $this->values = array_fill_keys($this->keys, $this->value);
    }

    public function setUpDirectories(array $params): void
    {
        $this->setUpEmpty($params);
        $getFile = new ReflectionMethod(FileCache::class, 'getCacheFile');
        foreach ($this->keys as $key) {
            $getFile->invoke($this->cache, $key, true);
        }
        clearstatcache();
    }

    public function setUpExpired(array $params): void
    {
        $this->setUpPopulated($params);
        $this->expire($this->keys);
    }

    public function cleanUp(): void
    {
        if (!isset($this->path) || !is_dir($this->path)) {
            return;
        }
        // Only remove this sample's randomly named directory, never the configured root.
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->path);
    }

    protected function expire(array $keys): void
    {
        // Expire real entries without sleeps or measuring the TTL <= 0 deletion shortcut.
        $getFile = new ReflectionMethod(FileCache::class, 'getCacheFile');
        foreach ($keys as $key) {
            $file = $getFile->invoke($this->cache, $key);
            $record = file_get_contents($file);
            if ($record === false || !str_starts_with($record, 'YFC1 ')) {
                throw new RuntimeException('Missing cache header in benchmark fixture.');
            }
            $record = preg_replace('/\AYFC1 [0-9]+ /', 'YFC1 ' . (time() - 3600) . ' ', $record, 1);
            if ($record === null || file_put_contents($file, $record) !== strlen($record)) {
                throw new RuntimeException('Cannot expire benchmark fixture.');
            }
            if ($this->cache->get($key) !== null || $this->cache->has($key)) {
                throw new RuntimeException('Benchmark fixture did not expire.');
            }
            $this->expectedValues[$key] = null;
        }
        clearstatcache();
    }

    protected function start(): void
    {
        if ($this->started) {
            throw new RuntimeException('Use exactly one revolution and zero warmup; increase iterations for more samples.');
        }
        $this->started = true;
    }
}
