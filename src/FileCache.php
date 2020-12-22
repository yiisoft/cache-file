<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File;

use DateInterval;
use DateTime;
use Exception;
use Psr\SimpleCache\CacheInterface;
use Traversable;

use function array_keys;
use function array_map;
use function closedir;
use function dirname;
use function error_get_last;
use function filemtime;
use function fileowner;
use function file_exists;
use function fopen;
use function function_exists;
use function gettype;
use function is_dir;
use function is_file;
use function is_iterable;
use function is_string;
use function iterator_to_array;
use function opendir;
use function posix_geteuid;
use function random_int;
use function readdir;
use function rmdir;
use function serialize;
use function strncmp;
use function strpbrk;
use function substr;
use function unlink;
use function unserialize;

use const LOCK_EX;
use const LOCK_SH;
use const LOCK_UN;

/**
 * FileCache implements a cache handler using files.
 *
 * For each data value being cached, FileCache will store it in a separate file.
 * The cache files are placed under {@see FileCache::$cachePath}.
 * FileCache will perform garbage collection automatically to remove expired cache files.
 *
 * Please refer to {@see \Psr\SimpleCache\CacheInterface} for common cache operations that are supported by FileCache.
 */
final class FileCache implements CacheInterface
{
    private const TTL_INFINITY = 31536000; // 1 year
    private const EXPIRATION_EXPIRED = -1;

    /**
     * @var string The directory to store cache files. You may use path alias here.
     *
     * @see https://github.com/yiisoft/docs/blob/master/guide/en/concept/aliases.md
     */
    private string $cachePath;

    /**
     * @var string The cache file suffix. Defaults to '.bin'.
     */
    private string $fileSuffix = '.bin';

    /**
     * @var int|null The permission to be set for newly created cache files.
     * This value will be used by PHP chmod() function. No umask will be applied.
     * If not set, the permission will be determined by the current environment.
     */
    private ?int $fileMode = null;

    /**
     * @var int The permission to be set for newly created directories.
     * This value will be used by PHP chmod() function. No umask will be applied.
     * Defaults to 0775, meaning the directory is read-writable by owner and group,
     * but read-only for other users.
     */
    private int $dirMode = 0775;

    /**
     * @var int The level of sub-directories to store cache files. Defaults to 1.
     * If the system has huge number of cache files (e.g. one million), you may use a bigger value
     * (usually no bigger than 3). Using sub-directories is mainly to ensure the file system
     * is not over burdened with a single directory having too many files.
     */
    private int $directoryLevel = 1;

    /**
     * @var int The probability (parts per million) that garbage collection (GC) should be performed
     * when storing a piece of data in the cache. Defaults to 10, meaning 0.001% chance.
     * This number should be between 0 and 1000000. A value 0 means no GC will be performed at all.
     */
    private int $gcProbability = 10;

    /**
     * @param string $cachePath The directory to store cache files.
     *
     * @see FileCache::$cachePath
     *
     * @throws CacheException If failed to create cache directory.
     */
    public function __construct(string $cachePath)
    {
        if (!$this->createDirectoryIfNotExists($cachePath)) {
            throw new CacheException("Failed to create cache directory \"{$cachePath}\".");
        }

        $this->cachePath = $cachePath;
    }

    public function get($key, $default = null)
    {
        $this->validateKey($key);
        $file = $this->getCacheFile($key);

        if (!$this->existsAndNotExpired($file) || ($filePointer = @fopen($file, 'rb')) === false) {
            return $default;
        }

        flock($filePointer, LOCK_SH);
        $value = stream_get_contents($filePointer);
        flock($filePointer, LOCK_UN);
        fclose($filePointer);

        return unserialize($value);
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->validateKey($key);
        $this->gc();
        $expiration = $this->ttlToExpiration($ttl);

        if ($expiration < 0) {
            return $this->delete($key);
        }

        $file = $this->getCacheFile($key);

        if ($this->directoryLevel > 0 && !$this->createDirectoryIfNotExists(dirname($file))) {
            return false;
        }

        // If ownership differs the touch call will fail, so we try to
        // rebuild the file from scratch by deleting it first
        // https://github.com/yiisoft/yii2/pull/16120
        if (function_exists('posix_geteuid') && is_file($file) && fileowner($file) !== posix_geteuid()) {
            @unlink($file);
        }

        if (file_put_contents($file, serialize($value), LOCK_EX) === false) {
            return false;
        }

        if ($this->fileMode !== null) {
            chmod($file, $this->fileMode);
        }

        return touch($file, $expiration);
    }

    public function delete($key): bool
    {
        $this->validateKey($key);
        $file = $this->getCacheFile($key);

        if (!file_exists($file)) {
            return true;
        }

        return @unlink($file);
    }

    public function clear(): bool
    {
        $this->removeCacheFiles($this->cachePath, false);
        return true;
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $keys = $this->iterableToArray($keys);
        $this->validateKeys($keys);
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        $values = $this->iterableToArray($values);
        $this->validateKeys(array_map('strval', array_keys($values)));

        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple($keys): bool
    {
        $keys = $this->iterableToArray($keys);
        $this->validateKeys($keys);

        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has($key): bool
    {
        $this->validateKey($key);
        return $this->existsAndNotExpired($this->getCacheFile($key));
    }

    /**
     * @param string $fileSuffix The cache file suffix. Defaults to '.bin'.
     *
     * @return self
     */
    public function withFileSuffix(string $fileSuffix): self
    {
        $new = clone $this;
        $new->fileSuffix = $fileSuffix;
        return $new;
    }

    /**
     * @param int $fileMode The permission to be set for newly created cache files.
     * This value will be used by PHP chmod() function. No umask will be applied.
     * If not set, the permission will be determined by the current environment.
     *
     * @return self
     */
    public function withFileMode(int $fileMode): self
    {
        $new = clone $this;
        $new->fileMode = $fileMode;
        return $new;
    }

    /**
     * @param int $dirMode The permission to be set for newly created directories.
     * This value will be used by PHP chmod() function. No umask will be applied.
     * Defaults to 0775, meaning the directory is read-writable by owner and group, but read-only for other users.
     *
     * @return self
     */
    public function withDirMode(int $dirMode): self
    {
        $new = clone $this;
        $new->dirMode = $dirMode;
        return $new;
    }

    /**
     * @param int $directoryLevel The level of sub-directories to store cache files. Defaults to 1.
     * If the system has huge number of cache files (e.g. one million), you may use a bigger value
     * (usually no bigger than 3). Using sub-directories is mainly to ensure the file system
     * is not over burdened with a single directory having too many files.
     *
     * @return self
     */
    public function withDirectoryLevel(int $directoryLevel): self
    {
        $new = clone $this;
        $new->directoryLevel = $directoryLevel;
        return $new;
    }

    /**
     * @param int $gcProbability The probability (parts per million) that garbage collection (GC) should
     * be performed when storing a piece of data in the cache. Defaults to 10, meaning 0.001% chance.
     * This number should be between 0 and 1000000. A value 0 means no GC will be performed at all.
     *
     * @return self
     */
    public function withGcProbability(int $gcProbability): self
    {
        $new = clone $this;
        $new->gcProbability = $gcProbability;
        return $new;
    }

    /**
     * Converts TTL to expiration
     *
     * @param DateInterval|int|null $ttl
     *
     * @return int
     */
    private function ttlToExpiration($ttl): int
    {
        $ttl = $this->normalizeTtl($ttl);

        if ($ttl === null) {
            return self::TTL_INFINITY + time();
        }

        if ($ttl <= 0) {
            return self::EXPIRATION_EXPIRED;
        }

        return $ttl + time();
    }

    /**
     * Normalizes cache TTL handling strings and {@see DateInterval} objects.
     *
     * @param DateInterval|int|string|null $ttl The raw TTL.
     *
     * @return int|null TTL value as UNIX timestamp or null meaning infinity
     */
    private function normalizeTtl($ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            return (new DateTime('@0'))->add($ttl)->getTimestamp();
        }

        return (int) $ttl;
    }

    /**
     * Ensures that the directory is created.
     *
     * @param string $path The path to the directory.
     *
     * @return bool Whether the directory was created.
     */
    private function createDirectoryIfNotExists(string $path): bool
    {
        return is_dir($path) || (mkdir($path, $this->dirMode, true) && is_dir($path));
    }

    /**
     * Returns the cache file path given the cache key.
     *
     * @param string $key The cache key.
     *
     * @return string The cache file path.
     */
    private function getCacheFile(string $key): string
    {
        if ($this->directoryLevel < 1) {
            return $this->cachePath . DIRECTORY_SEPARATOR . $key . $this->fileSuffix;
        }

        $base = $this->cachePath;

        for ($i = 0; $i < $this->directoryLevel; ++$i) {
            if (($prefix = substr($key, $i + $i, 2)) !== false) {
                $base .= DIRECTORY_SEPARATOR . $prefix;
            }
        }

        return $base . DIRECTORY_SEPARATOR . $key . $this->fileSuffix;
    }

    /**
     * Recursively removing expired cache files under a directory. This method is mainly used by {@see gc()}.
     *
     * @param string $path The directory under which expired cache files are removed.
     * @param bool $expiredOnly Whether to only remove expired cache files.
     * If false, all files under `$path` will be removed.
     */
    private function removeCacheFiles(string $path, bool $expiredOnly): void
    {
        if (($handle = @opendir($path)) === false) {
            return;
        }

        while (($file = readdir($handle)) !== false) {
            if (strncmp($file, '.', 1) === 0) {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $file;

            if (is_dir($fullPath)) {
                $this->removeCacheFiles($fullPath, $expiredOnly);

                if (!$expiredOnly && !@rmdir($fullPath)) {
                    $errorMessage = error_get_last()['message'] ?? '';
                    throw new CacheException("Unable to remove directory '{$fullPath}': {$errorMessage}");
                }
            } elseif ((!$expiredOnly || @filemtime($fullPath) < time()) && !@unlink($fullPath)) {
                $errorMessage = error_get_last()['message'] ?? '';
                throw new CacheException("Unable to remove file '{$fullPath}': {$errorMessage}");
            }
        }

        closedir($handle);
    }

    /**
     * Removes expired cache files.
     *
     * @throws Exception
     */
    private function gc(): void
    {
        if (random_int(0, 1000000) < $this->gcProbability) {
            $this->removeCacheFiles($this->cachePath, true);
        }
    }

    /**
     * @param mixed $key
     */
    private function validateKey($key): void
    {
        if (!is_string($key) || $key === '' || strpbrk($key, '{}()/\@:')) {
            throw new InvalidArgumentException('Invalid key value.');
        }
    }

    /**
     * @param array $keys
     */
    private function validateKeys(array $keys): void
    {
        foreach ($keys as $key) {
            $this->validateKey($key);
        }
    }

    /**
     * @param string $file
     *
     * @return bool
     */
    private function existsAndNotExpired(string $file): bool
    {
        return file_exists($file) && @filemtime($file) > time();
    }

    /**
     * Converts iterable to array. If provided value is not iterable it throws an InvalidArgumentException.
     *
     * @param mixed $iterable
     *
     * @return array
     */
    private function iterableToArray($iterable): array
    {
        if (!is_iterable($iterable)) {
            throw new InvalidArgumentException('Iterable is expected, got ' . gettype($iterable));
        }

        /** @psalm-suppress RedundantCast */
        return $iterable instanceof Traversable ? iterator_to_array($iterable) : (array) $iterable;
    }
}
