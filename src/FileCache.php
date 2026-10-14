<?php

declare(strict_types=1);

namespace Yiisoft\Cache\File;

use DateInterval;
use DateTime;
use Psr\SimpleCache\CacheInterface;
use Traversable;

use function array_keys;
use function array_map;
use function bin2hex;
use function closedir;
use function dirname;
use function error_get_last;
use function file_exists;
use function filectime;
use function is_dir;
use function is_file;
use function iterator_to_array;
use function mkdir;
use function opendir;
use function random_bytes;
use function readdir;
use function restore_error_handler;
use function serialize;
use function set_error_handler;
use function sprintf;
use function strpbrk;
use function substr;
use function unserialize;

use function strlen;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use const PHP_OS_FAMILY;

/**
 * `FileCache` implements a cache handler using files.
 *
 * For each data value being cached, `FileCache` will store it in a separate file. The cache files are placed
 * under {@see FileCache::$cachePath}. `FileCache` will perform garbage collection automatically to remove expired
 * cache files.
 *
 * Please refer to {@see CacheInterface} for common cache operations that are supported by `FileCache`.
 */
final class FileCache implements CacheInterface
{
    private const TTL_INFINITY = 31_536_000; // 1 year
    private const EXPIRATION_EXPIRED = -1;
    /**
     * Cache keys can't contain "@", so neither a cache file nor a cache directory can start with it.
     */
    private const TEMPORARY_FILE_PREFIX = '@';
    private const TEMPORARY_FILE_LIFETIME = 3600;
    private const GC_LOCK_FILE = '@gc.lock';

    private bool $useReadLock = PHP_OS_FAMILY === 'Windows';

    /**
     * @param string $cachePath The directory to store cache files.
     * @param int $directoryMode The permission to be set for newly created directories. This value will be used
     * by PHP `chmod()` function. No umask will be applied. Defaults to 0775, meaning the directory is read-writable
     * by owner and group, but read-only for other users.
     * @param string $fileSuffix The cache file suffix. Defaults to '.bin'.
     * @param int|null $fileMode The permission to be set for newly created cache files.
     * This value will be used by PHP chmod() function. No umask will be applied.
     * If not set, the permission will be determined by the current environment.
     * @param int $directoryLevel The level of subdirectories to store cache files. Defaults to 1.
     * If the system has huge number of cache files (e.g. one million), you may use a bigger value
     * (usually no bigger than 3). Using subdirectories is mainly to ensure the file system
     * is not over burdened with a single directory having too many files.
     * @param int $gcProbability The probability (parts per million) that garbage collection (GC) should be performed.
     * when storing a piece of data in the cache. Defaults to 10, meaning 0.001% chance.
     * This number should be between 0 and 1000000. A value 0 means no GC will be performed at all.
     *
     * @see FileCache::$cachePath
     *
     * @throws CacheException If failed to create cache directory.
     */
    public function __construct(
        private readonly string $cachePath,
        private int $directoryMode = 0775,
        private string $fileSuffix = '.bin',
        private ?int $fileMode = null,
        private int $directoryLevel = 1,
        private int $gcProbability = 10,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $file = $this->getCacheFile($key);

        $handle = false;
        $lock = null;
        if ($this->useReadLock && ($lock = $this->lockForRead()) === false) {
            return $default;
        }
        try {
            if (($handle = @fopen($file, 'rb')) === false) {
                return $default;
            }
            $metadata = $this->readMetadata($handle);
            if ($metadata === null || $metadata['expiration'] <= time()) {
                return $default;
            }
            $value = @stream_get_contents($handle, $metadata['length']);
            if ($value === false || $value === '' || strlen($value) !== $metadata['length']) {
                return $default;
            }
        } finally {
            if ($handle !== false) {
                fclose($handle);
            }
            if ($lock !== null && $lock !== false) {
                fclose($lock);
            }
        }
        return unserialize($value);
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->gc();
        $expiration = $this->ttlToExpiration($ttl);

        if ($expiration <= self::EXPIRATION_EXPIRED) {
            return $this->delete($key);
        }

        $file = $this->getCacheFile($key, ensureDirectory: true);

        // Write to a temporary file and move it into place, so other processes
        // see either the previous or the new value and never a partial one.
        $temporaryFile = dirname($file) . DIRECTORY_SEPARATOR
            . self::TEMPORARY_FILE_PREFIX . bin2hex(random_bytes(8)) . '.tmp';
        $payload = serialize($value);
        $header = 'YFC1 ' . $expiration . ' ' . strlen($payload) . "\n";
        $data = [$header, $payload];
        $length = strlen($header) + strlen($payload);
        $published = false;

        try {
            if (@file_put_contents($temporaryFile, $data) !== $length) {
                // The directory could be removed in the meantime, so try once more.
                $this->getCacheFile($key, ensureDirectory: true);
                if (@file_put_contents($temporaryFile, $data) !== $length) {
                    return false;
                }
            }
            if ($this->fileMode !== null && !@chmod($temporaryFile, $this->fileMode)) {
                return false;
            }

            $lock = $this->openGcLock(exclusive: $this->useReadLock);
            if ($lock === false) {
                return false;
            }
            try {
                // Windows cannot replace a file while a reader has it open.
                // Elsewhere, writers may publish concurrently, except during GC.
                $published = flock($lock, $this->useReadLock ? LOCK_EX : LOCK_SH)
                    && @rename($temporaryFile, $file);
            } finally {
                fclose($lock);
            }
            if ($published) {
                clearstatcache();
            }
            return $published;
        } finally {
            if (!$published) {
                @unlink($temporaryFile);
            }
        }
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $file = $this->getCacheFile($key);

        return !is_file($file) || $this->removeFile($file);
    }

    public function clear(): bool
    {
        $this->removeCacheFiles($this->cachePath, false);
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = $this->iterableToArray($keys);
        $this->validateKeys($keys);
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        $values = $this->iterableToArray($values);
        $this->validateKeys(array_map(\strval(...), array_keys($values)));

        $success = true;
        foreach ($values as $key => $value) {
            $success = $this->set((string) $key, $value, $ttl) && $success;
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $keys = $this->iterableToArray($keys);
        $this->validateKeys($keys);

        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        return $this->existsAndNotExpired($this->getCacheFile($key));
    }

    /**
     * @param string $fileSuffix The cache file suffix. Defaults to '.bin'.
     *
     * @deprecated Use `$fileSuffix` in the constructor instead.
     */
    public function withFileSuffix(string $fileSuffix): self
    {
        $new = clone $this;
        $new->fileSuffix = $fileSuffix;
        return $new;
    }

    /**
     * @param int $fileMode The permission to be set for newly created cache files. This value will be used
     * by PHP `chmod()` function. No umask will be applied. If not set, the permission will be determined
     * by the current environment.
     *
     * @deprecated Use `$fileMode` in the constructor instead.
     */
    public function withFileMode(int $fileMode): self
    {
        $new = clone $this;
        $new->fileMode = $fileMode;
        return $new;
    }

    /**
     * @param int $directoryMode The permission to be set for newly created directories. This value will be used
     * by PHP `chmod()` function. No umask will be applied. Defaults to 0775, meaning the directory is read-writable
     * by owner and group, but read-only for other users.
     *
     * @deprecated Use `$directoryMode` in the constructor instead.
     */
    public function withDirectoryMode(int $directoryMode): self
    {
        $new = clone $this;
        $new->directoryMode = $directoryMode;
        return $new;
    }

    /**
     * @param int $directoryLevel The level of subdirectories to store cache files. Defaults to 1.
     * If the system has huge number of cache files (e.g. one million), you may use a bigger value
     * (usually no bigger than 3). Using subdirectories is mainly to ensure the file system
     * is not over burdened with a single directory having too many files.
     *
     * @deprecated Use `$directoryLevel` in the constructor instead.
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
     * @deprecated Use `$gcProbability` in the constructor instead.
     */
    public function withGcProbability(int $gcProbability): self
    {
        $new = clone $this;
        $new->gcProbability = $gcProbability;
        return $new;
    }

    /**
     * Converts TTL to expiration.
     */
    private function ttlToExpiration(int|string|DateInterval|null $ttl = null): int
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
     * @return int|null TTL value as UNIX timestamp or null meaning infinity.
     */
    private function normalizeTtl(int|string|DateInterval|null $ttl = null): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            return (new DateTime('@0'))
                ->add($ttl)
                ->getTimestamp();
        }

        return (int) $ttl;
    }

    /**
     * Ensures that the directory exists.
     *
     * @param string $path The path to the directory.
     */
    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (is_file($path)) {
            throw new CacheException("Failed to create cache directory, file with the same name exists: \"$path\".");
        }

        set_error_handler(
            static function (int $errorNumber, string $errorString) use ($path): bool {
                if (is_dir($path)) {
                    return true;
                }
                throw new CacheException(
                    sprintf('Failed to create directory "%s". %s', $path, $errorString),
                    $errorNumber,
                );
            },
        );
        try {
            mkdir($path, recursive: true);
        } finally {
            restore_error_handler();
        }

        chmod($path, $this->directoryMode);
    }

    /**
     * Returns the cache file path given the cache key.
     *
     * @param string $key The cache key.
     *
     * @return string The cache file path.
     */
    private function getCacheFile(string $key, bool $ensureDirectory = false): string
    {
        if ($ensureDirectory) {
            $this->ensureDirectory($this->cachePath);
        }

        if ($this->directoryLevel < 1) {
            return $this->cachePath . DIRECTORY_SEPARATOR . $key . $this->fileSuffix;
        }

        $base = $this->cachePath;

        for ($i = 0; $i < $this->directoryLevel; ++$i) {
            if (($prefix = substr($key, $i + $i, 2)) !== '') {
                $base .= DIRECTORY_SEPARATOR . $prefix;
                if ($ensureDirectory) {
                    $this->ensureDirectory($base);
                }
            }
        }

        return $base . DIRECTORY_SEPARATOR . $key . $this->fileSuffix;
    }

    /**
     * Recursively removing cache files under a directory. Directories are kept, because another process
     * could be writing to them. This method is mainly used by {@see gc()}.
     *
     * @param string $path The directory under which cache files are removed.
     * @param bool $expiredOnly Whether to only remove expired cache files.
     * If false, all cache files under `$path` will be removed.
     */
    private function removeCacheFiles(string $path, bool $expiredOnly, int $depth = 0): void
    {
        if (($handle = @opendir($path)) === false) {
            return;
        }

        try {
            while (($file = readdir($handle)) !== false) {
                if ($file === '.' || $file === '..' || $file === self::GC_LOCK_FILE) {
                    continue;
                }

                $fullPath = $path . DIRECTORY_SEPARATOR . $file;

                if (is_dir($fullPath)) {
                    // Hidden directories can only be cache shards at the configured depth.
                    if ($file[0] !== '.' || (strlen($file) === 2 && $depth < $this->directoryLevel)) {
                        $this->removeCacheFiles($fullPath, $expiredOnly, $depth + 1);
                    }
                    continue;
                }

                if (str_starts_with($file, self::TEMPORARY_FILE_PREFIX)) {
                    // A temporary file is in use by `set()` unless it was left by a terminated process.
                    $created = @filectime($fullPath);
                    $remove = $created !== false && $created < time() - self::TEMPORARY_FILE_LIFETIME;
                } elseif (
                    str_starts_with($file, '.')
                    && !$this->hasFileSuffix($file)
                    && ($this->fileSuffix !== '' || !$this->hasCacheHeader($fullPath))
                ) {
                    // Not a cache file, for example, ".gitignore".
                    $remove = false;
                } elseif ($expiredOnly) {
                    $this->removeExpiredFile($fullPath);
                    continue;
                } else {
                    $remove = true;
                }

                if ($remove && !$this->removeFile($fullPath)) {
                    $errorMessage = error_get_last()['message'] ?? '';
                    throw new CacheException("Unable to remove file '{$fullPath}': {$errorMessage}");
                }
            }
        } finally {
            closedir($handle);
        }
    }

    /** @return resource|false */
    private function openGcLock(bool $exclusive = false)
    {
        $file = $this->cachePath . DIRECTORY_SEPARATOR . self::GC_LOCK_FILE;
        // NFS requires write access for exclusive locks. Other filesystems may allow read-only access.
        if ($exclusive && ($lock = @fopen($file, 'r+b')) !== false) {
            return $lock;
        }
        if (($lock = @fopen($file, 'rb')) !== false) {
            return $lock;
        }
        if (($lock = @fopen($file, 'x+b')) === false) {
            // Another process may have created it since our first open.
            if ($exclusive && ($lock = @fopen($file, 'r+b')) !== false) {
                return $lock;
            }
            return @fopen($file, 'rb');
        }
        if ($this->fileMode !== null && !@chmod($file, $this->fileMode | 0600)) {
            fclose($lock);
            return false;
        }
        return $lock;
    }

    /** @return resource|false */
    private function lockForRead()
    {
        $lock = $this->openGcLock();
        if ($lock !== false && !flock($lock, LOCK_SH)) {
            fclose($lock);
            return false;
        }
        return $lock;
    }

    private function isExpired(string $file, bool $locked = false): bool
    {
        $handle = false;
        $lock = null;
        if ($this->useReadLock && !$locked && ($lock = $this->lockForRead()) === false) {
            return false;
        }
        try {
            if (($handle = @fopen($file, 'rb')) === false) {
                return false;
            }
            $metadata = $this->readMetadata($handle);
            return $metadata !== null && $metadata['expiration'] <= time();
        } finally {
            if ($handle !== false) {
                fclose($handle);
            }
            if ($lock !== null && $lock !== false) {
                fclose($lock);
            }
        }
    }

    private function removeExpiredFile(string $file): void
    {
        if (!$this->isExpired($file) || ($lock = $this->openGcLock(exclusive: true)) === false) {
            return;
        }
        try {
            // Skip a busy publisher. Recheck under the lock: it may have refreshed this key.
            if (flock($lock, LOCK_EX | LOCK_NB) && $this->isExpired($file, locked: true) && !$this->removeFile($file)) {
                $errorMessage = error_get_last()['message'] ?? '';
                throw new CacheException("Unable to remove file '{$file}': {$errorMessage}");
            }
        } finally {
            fclose($lock);
        }
    }

    private function hasCacheHeader(string $file): bool
    {
        $handle = false;
        $lock = null;
        if ($this->useReadLock && ($lock = $this->lockForRead()) === false) {
            return false;
        }
        try {
            if (($handle = @fopen($file, 'rb')) === false) {
                return false;
            }
            return fread($handle, 5) === 'YFC1 ';
        } finally {
            if ($handle !== false) {
                fclose($handle);
            }
            if ($lock !== null && $lock !== false) {
                fclose($lock);
            }
        }
    }

    private function hasFileSuffix(string $file): bool
    {
        return $this->fileSuffix !== '' && str_ends_with($file, $this->fileSuffix);
    }

    /**
     * Removes the file. It isn't a failure if the file was already removed by another process.
     */
    private function removeFile(string $file): bool
    {
        // Another process can remove the file and then store a new one
        // in its place right before the check, so try more than once.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            error_clear_last();

            if (@unlink($file) || str_ends_with(error_get_last()['message'] ?? '', 'No such file or directory')) {
                return true;
            }

            // The error isn't available when an error handler has handled it.
            clearstatcache(true, $file);

            if (!file_exists($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes expired cache files.
     */
    private function gc(): void
    {
        if (random_int(0, 1_000_000) < $this->gcProbability) {
            $this->removeCacheFiles($this->cachePath, true);
        }
    }

    private function validateKey(string $key): void
    {
        if ($key === '' || strpbrk($key, '{}()/\@:')) {
            throw new InvalidArgumentException('Invalid key value.');
        }
    }

    /**
     * @param string[] $keys
     */
    private function validateKeys(array $keys): void
    {
        foreach ($keys as $key) {
            $this->validateKey($key);
        }
    }

    private function existsAndNotExpired(string $file): bool
    {
        $handle = false;
        $lock = null;
        if ($this->useReadLock && ($lock = $this->lockForRead()) === false) {
            return false;
        }
        try {
            if (($handle = @fopen($file, 'rb')) === false) {
                return false;
            }
            $metadata = $this->readMetadata($handle);
            if ($metadata === null || $metadata['expiration'] <= time()) {
                return false;
            }
            if ($metadata['length'] <= 4096) {
                // Small values normally fit in the stream's read buffer after reading the header.
                $payload = @stream_get_contents($handle, $metadata['length']);
                return $payload !== false && strlen($payload) === $metadata['length'];
            }
            $stat = fstat($handle);
            // Check payload availability without reading or deserializing the value.
            $offset = ftell($handle);
            return $stat !== false && $offset !== false && $stat['size'] - $offset >= $metadata['length'];
        } finally {
            if ($handle !== false) {
                fclose($handle);
            }
            if ($lock !== null && $lock !== false) {
                fclose($lock);
            }
        }
    }

    /**
     * Read metadata from an immutable opened file and leave the stream at the serialized value.
     * Legacy files remain readable; older implementations cannot read the new format.
     *
     * @param resource $handle
     * @return array{expiration: int, length: int}|null
     */
    private function readMetadata($handle): ?array
    {
        $header = @fgets($handle, 128);
        if ($header === false) {
            return null;
        }
        if (str_starts_with($header, 'YFC1 ')) {
            if (preg_match('/\AYFC1 ([0-9]+) ([0-9]+)\n\z/', $header, $matches) !== 1) {
                return null;
            }
            $length = (int) $matches[2];
            if ($length <= 0) {
                return null;
            }
            return [
                'expiration' => (int) $matches[1],
                'length' => $length,
            ];
        }

        // Migration path for serialized values whose expiration was stored in mtime.
        $stat = fstat($handle);
        if ($stat === false || !rewind($handle) || $stat['size'] === 0) {
            return null;
        }
        return ['expiration' => $stat['mtime'], 'length' => $stat['size']];
    }

    /**
     * Converts iterable to array.
     *
     * @psalm-template TKey
     * @psalm-template TValue
     * @psalm-param iterable<TKey, TValue> $iterable
     * @psalm-return array<TKey, TValue>
     */
    private function iterableToArray(iterable $iterable): array
    {
        return $iterable instanceof Traversable ? iterator_to_array($iterable) : $iterable;
    }
}
