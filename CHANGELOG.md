# Yii Cache Library - File Handler Change Log

## 3.2.1 under development

- Chg #90, #91, #95: Adjust supported PHP versions to be 8.1 — 8.5 (@vjik, @samdark)
- Enh #97: Explicitly import classes and constants in "use" section (@mspirkov)
- Chg #93: Deprecate `FileCache::with*()` methods in favor of constructor parameters (@samdark)
- Enh #101: Remove unnecessary files from Composer package (@mspirkov)
- Bug #110: Replace cache files atomically in `FileCache::set()` to prevent partial reads and gaps during replacement (@samdark)
- Bug #110: Coordinate Windows reads with file replacement and reuse cache locks owned by other users (@samdark)
- Chg #110: Store expiry inside cache files. Clear the cache on upgrade or rollback;
  see [UPGRADE.md](UPGRADE.md) for deployment instructions (@samdark)
- Bug #110: Handle incomplete writes and file replacement failures in `FileCache::set()`, and suppress write warnings (@samdark)
- Bug #110: Return `false` from `FileCache::setMultiple()` when any write fails, while still attempting remaining entries (@samdark)
- Bug #110: Coordinate file replacement with garbage collection to keep fresh values and skip unreadable entries,
  using writable handles for exclusive locks on NFS (@samdark)
- Bug #110: Avoid exceptions during garbage collection and `FileCache::clear()` when another process has already
  removed a file (@samdark)
- Chg #110: Retain directories, active temporary files and the internal lock file during garbage collection and
  `FileCache::clear()` to avoid races with concurrent writers (@samdark)
- Bug #110: Remove cache entries with dot-prefixed keys during garbage collection and `FileCache::clear()`, including
  entries in the new format with an empty file suffix, while preserving unrelated hidden directories (@samdark)
- Bug #110: Treat truncated entries in the new format as cache misses in `FileCache::get()` and `FileCache::has()` (@samdark)

## 3.2.0 January 13, 2025

- Enh #88: Don't create cache directory on `FileCache` initialization (@vjik)
- Bug #85: Clear stat cache in `FileCache::set()` (@samdark)
- Bug #86: Handle race condition when creating cache directory (@vjik)
- Bug #88: Set correct permissions for nested directories (@vjik)

## 3.1.0 October 09, 2023

- Chg #69: Add optional parameter `$directoryMode` to `FileCache` constructor and deprecate `withDirectoryMode()`
  method (@particleflux)
- Enh #70: Minor refactoring with PHP 8 features usage (@vjik)

## 3.0.0 February 15, 2023

- Chg #50: Do not throw exception on file delete when file not found (fix for high concurrency load) (@sartor)
- Chg #52: Do not throw exception on change mode and modification time of cache file (fix for high
  concurrency load) (@sartor)
- Chg #56: Adapt configuration group names to Yii conventions (@vjik)

## 2.0.1 September 18, 2022

- Bug #47: Set permissions for new directory via `chmod()` (@dehbka)

## 2.0.0 July 21, 2022

- Chg #44: Raise the minimum `psr/simple-cache` version to `^2.0|^3.0` and the minimum PHP version to `^8.0` (@dehbka)

## 1.0.1 March 23, 2021

- Chg: Adjust config for new config plugin (@samdark)

## 1.0.0 February 02, 2021

- Initial release.
