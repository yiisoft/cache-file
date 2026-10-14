# Upgrade guide

## Upgrading to 3.2.1 (under development)

New cache files store the expiry time with the serialized value. Older versions store expiry in the file's
modification time. The new version can read old files, but older versions cannot read the new format.
Old and new versions must use separate cache directories if they run at the same time.

Public method signatures and constructor defaults are unchanged. `setMultiple()` now returns `false`
if any write fails and still attempts the remaining writes.

The package configuration still uses `@runtime/cache` by default. To reuse this directory, clear its cached
contents while all processes that use it are stopped.

### Upgrade with all processes stopped

1. Stop all processes that use the cache, including web requests, queue workers, and scheduled jobs.
2. Remove the cached contents. Keep or recreate the directory with the permissions the application needs.
3. Upgrade the package in every application instance that shares the directory.
4. Restart the processes. Expect cache misses until the application creates new entries.

Clearing the cache while both versions use it is not sufficient: the next write from the new version
creates a file that the old version cannot read.

### Rolling upgrade

A rolling upgrade runs old and new application instances at the same time. Separate cache directories
prevent old instances from reading files written in the new format.

1. Configure the new version to use a separate, empty directory. For example, if the old version uses
   `/var/cache/my-app/file-cache`, configure the new version as follows:

   ```php
   $cache = new \Yiisoft\Cache\File\FileCache('/var/cache/my-app/file-cache-v2');
   ```

2. Deploy the new version and restart its workers with this configuration. Expect cache misses, or
   populate the new cache before sending requests to the new instances.
3. Stop all old instances, including background workers and scheduled jobs.
4. Remove the old cache directory after all processes have stopped using it.

Do not place either cache directory inside the other. `clear()` and garbage collection traverse
subdirectories. Different key prefixes or file suffixes do not provide this separation.

If application data changes while both versions run, invalidate affected entries in both caches.

### Rollback

Give the older version a separate, empty directory. It cannot read files written by the new version.
If both versions run during rollback, keep their directories separate.

To reuse the same directory, stop all processes that use it, remove its cached contents, deploy the
older version, and restart the processes. Expect cache misses until the application creates new entries.

### Expiry and cleanup scripts

File modification times no longer control expiry for new entries. Update scripts that use `touch()`
to change expiry or use modification times to find expired entries. Use `delete()` to remove an entry
or `set()` with a TTL to replace its value and expiry. Garbage collection removes expired entries.

`clear()` and garbage collection keep empty directories and the internal `@gc.lock` file. Other processes
may still use them. Remove them only after all processes that use the cache have stopped.
Do not delete or replace `@gc.lock` while the cache is in use.

On Linux NFS, workers running garbage collection need read and write access to `@gc.lock`.
If workers use different users, configure a shared group and a group-writable `fileMode`, such as `0660`.
Adjust permissions on an existing lock file as well; changing `fileMode` does not update it.

### Windows

On Windows, reads hold a shared lock. File replacement waits until cache readers close their files.
If an external program holds a cache file open, replacement can still fail. In that case, `set()`
returns `false` and keeps the previous value. Other platforms do not lock reads.
