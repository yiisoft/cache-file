# Internals

## Cache files

New entries contain a header followed by the serialized PHP value:

```text
YFC1 <expiry timestamp> <serialized byte length>\n
<serialized value>
```

The header ends with one newline. `get()` reads the expiry time and value from the same open file.
If another process replaces that file, the reader still uses matching expiry data and value bytes.
Old files have no header; their modification time provides the expiry time.

`has()` checks expiry and payload length without deserializing the value. For payloads up to 4 KiB,
it reads the payload, which is usually already in the stream buffer. For larger payloads, it checks
the size of the open file. Both `get()` and `has()` reject incomplete payloads in the new format.

### Writing and locking

`set()` writes a complete record to a temporary file in the same directory. It checks the byte count,
applies the configured permissions, and renames the file into place. A short random filename keeps
temporary names independent of key length. Writing the header and payload as separate chunks avoids
a second full copy of the serialized value in memory.

Readers see a complete old or new file. This is atomic replacement; it does not guarantee that data
survives a power failure.

On platforms other than Windows, reads take no lock. File replacement holds a shared lock on
`@gc.lock` in the cache root directory. On Windows, reads hold a shared lock while the cache file is
open, and replacement requires an exclusive lock. This makes replacement wait for cache readers.
If an external program keeps the file open, replacement can still fail. `set()` then returns `false`
and keeps the old value.

Shared locks open existing lock files read-only. Exclusive locks try read-write access first, then
fall back to read-only access for filesystems that support it. Linux NFS requires write access for
exclusive locks, so workers running GC need write access to `@gc.lock`.
Permissions are set only when a lock file is created; other workers do not need to own it.

### Cleanup

Before deleting an expired entry, garbage collection (GC) takes an exclusive lock on `@gc.lock`
and checks expiry again. This prevents deletion of a value replaced since the first check.
If the exclusive lock is unavailable, GC skips that deletion. GC also skips entries it cannot read
or whose expiry it cannot determine.

GC and `clear()` keep directories and the lock file. They keep temporary files created within the
last hour and can remove older temporary files. Remove retained directories and the lock file only
after all processes that use the cache have stopped. Deleting the lock file during use breaks the
coordination between file replacement and GC.

A cache read can still miss if another process deletes the entry or GC removes it after expiry.

Old and new package versions must use separate directories when they run at the same time.
Old versions cannot read the new header and do not follow the new locking rules.
See the [upgrade guide](../UPGRADE.md).

## Unit testing

The package is tested with [PHPUnit](https://phpunit.de/). To run tests:

```shell
./vendor/bin/phpunit
```

## Mutation testing

The package tests are checked with [Infection](https://infection.github.io/) mutation framework with
[Infection Static Analysis Plugin](https://github.com/Roave/infection-static-analysis-plugin). To run it:

```shell
./vendor/bin/roave-infection-static-analysis-plugin
```

## Static analysis

The code is statically analyzed with [Psalm](https://psalm.dev/). To run static analysis:

```shell
./vendor/bin/psalm
```

## Code style

Use [Rector](https://github.com/rectorphp/rector) to make codebase follow some specific rules or
use either newest or any specific version of PHP:

```shell
./vendor/bin/rector
```

## Dependencies

Use [Composer Dependency Analyser](https://github.com/shipmonk-rnd/composer-dependency-analyser) to detect unknown,
shadow, and unused [Composer](https://getcomposer.org) dependencies:

```shell
./vendor/bin/composer-dependency-analyser
```
