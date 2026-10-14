# Upgrade guide

## Upgrading to 3.2.1 (under development)

Cache writes now atomically publish a file containing both the expiry and the serialized value.
Previously, expiry was stored in the file's modification time.

Public method signatures and constructor defaults are unchanged. `setMultiple()` now correctly returns
`false` if any write fails, while still attempting the remaining entries. The new implementation can read existing cache files,
but older package versions cannot read the new format. Old and new processes must not share a cache directory.

The default cache path remains `@runtime/cache`. Clear the cache as part of the upgrade procedure below.

### Upgrade with all processes stopped

1. Stop every process using the cache, including web requests, queue workers, and scheduled jobs.
2. Remove the cached contents of the cache directory while those processes are stopped. Preserve or recreate
   the directory with the permissions required by the application.
3. Upgrade the package in every application instance that shares the cache directory.
4. Restart the processes with the new version. Expect cache misses until entries are rebuilt.

Do not clear the cache while old and new versions are running against the same directory. Cleaning it once
cannot make mixed-version access safe: the new version will immediately start writing the new format again.

### Rolling upgrade

1. Configure the new version to use a separate, empty cache directory. For example, if the old version uses
   `/var/cache/my-app/file-cache`, use `/var/cache/my-app/file-cache-v2` for the new version:

   ```php
   $cache = new \Yiisoft\Cache\File\FileCache('/var/cache/my-app/file-cache-v2');
   ```

2. Deploy the new version and restart its workers with that configuration. Allow for cache misses
   while the new directory fills, or warm it using the new version before directing traffic to it.
3. Retire all old processes, including background workers and scheduled jobs.
4. Remove the old cache directory only after no process uses it.

Use directories that are not nested inside each other: `clear()` and garbage collection traverse
subdirectories. Changing only key prefixes or the file suffix does not isolate the two versions.

The caches are independent during the overlap. If your application requires invalidation on data changes,
propagate those invalidations to both caches.

### Rollback

Configure the older version to use a fresh, empty cache directory. Do not point it at files written by
the new version. If both versions run during the rollback, keep their cache directories separate.

To reuse the same directory, first stop every process using it, remove its cached contents, deploy the
older version, and then restart the processes. Expect cache misses until entries are rebuilt.

### Expiry and cleanup integrations

Modification times no longer indicate expiry for new entries. Update any scripts that expire entries
with `touch()` or identify expired entries by modification time. Use the cache API to delete entries
or change their TTL, and use the package's garbage collection for expiry cleanup.

`clear()` and garbage collection retain empty directories and the internal `@gc.lock` file for concurrency
safety. Remove these only while all processes using the cache are stopped. Do not delete or replace
`@gc.lock` in an online cleanup script.
