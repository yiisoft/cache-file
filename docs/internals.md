# Internals

## Cache files

New entries contain a header followed by the PHP-serialized value:

```text
YFC1 <expiration timestamp> <serialized byte length>\n
<serialized value>
```

The header ends with a single newline. `get()` opens the file once and reads both expiry and value from that
handle, so a concurrent replacement cannot mix metadata from one entry with another entry's value.
`has()` and garbage collection read the expiry from the header. `has()` rejects truncated payloads by checking up to
4 KiB of payload, usually already buffered during the header read, or checking the opened file size for
larger values. It never deserializes the value. New entries do not use modification times
for expiration. Legacy files without a header are read using their modification time.

`set()` writes the complete record to a short random temporary filename in the same directory, checks the
byte count, applies the configured permissions, and renames it into place. Header and payload are written
as separate chunks to avoid allocating a second full copy of the serialized value. Published files are not modified
in place, so readers need no shared lock. This provides atomic visibility, not durability against power loss.
Publication holds a shared lock on the cache root’s `@gc.lock` file. Before removing an expired entry,
garbage collection takes an exclusive lock and rechecks its expiry, so it cannot delete a freshly published
replacement. Live entries are scanned without this lock; busy publishers cause GC to skip deletion.
Unreadable entries and entries with unrecognizable expiry are left alone. Readers do not take this lock.

Garbage collection and `clear()` preserve the lock file, directories and active temporary files; stale
temporary files are eligible for cleanup after one hour. Remove retained directories and the lock file only after stopping
all processes using the cache. Removing the lock file during use breaks publication/GC coordination.
Cache reads can still miss when entries are concurrently deleted or collected.

Use a separate cache directory when old and new package versions run simultaneously: old readers do not
understand the header, and legacy in-place writers do not follow the atomic publication protocol.

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
