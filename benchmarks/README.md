# File cache benchmarks

Install development dependencies with `composer install`, then run:

```sh
composer benchmark
```

The suite uses [PHPBench](https://phpbench.readthedocs.io/en/latest/annotributes.html)
with seven independent samples per scenario. For a quick correctness smoke run:

```sh
composer benchmark:smoke
```

## Scenarios and units

| Benchmark | Work per reported sample |
| --- | --- |
| `OperationsBench` | 100 single-key calls, or one `*Multiple()` call with 100 keys |
| `WorkloadBench` | 100 interleaved calls: 95/5, 80/20, or 50/50 read/write ratios |
| `MaintenanceBench` | One clear or GC sweep over 100 or 1,000 entries |

Operations cover hits, absent keys, expired entries, `has()`, inserts into existing
directories, inserts that create directories, overwrites, deletes, immediate-expiry
writes, and batch reads/writes/deletes. Values include 1 KiB and 64 KiB strings and a
structured array. The 1 KiB cases compare directory levels 0, 1, and 2. Misses use
existing directories. Mixed workloads read hits and overwrite existing keys; they
exclude application computation and cache-miss rebuilding.

**Reported times are per batch, not per key.** Divide the operation/workload time by
100 for average per-key cost. Do not divide maintenance results when comparing the
latency of a clear or GC sweep. Timing includes loop and result-recording overhead;
result comparisons, fixture preparation, and cleanup run outside the timed region.
PHPBench memory figures include the fixture and retained results, so they do not
represent the cache operation's incremental allocation.

## Fixture isolation

Each sample runs in PHPBench's default remote executor with its own randomly named
cache directory. Keys, payloads, and workload order are deterministic. Setup checks
that seeded entries can be read. After timing, the suite checks operation results
and final values; maintenance also checks that expired/deleted files are physically
gone. Cleanup removes only the sample's directory, including on ordinary PHP errors.
A killed process may leave `sample-*` directories in the benchmark root.

Each subject runs **once per sample, with no warmup revolutions**. A subject already
contains a batch large enough to measure. Repeating an insert, delete, or clear on
the same fixture would change the operation being measured. The suite fails if
`--revs` or `--warmup` causes a subject to run twice. Increase `--iterations` instead.
Do not switch to an executor that changes fixture lifecycle semantics.

Normal scenarios disable probabilistic GC. The GC benchmark explicitly invokes the
private `removeCacheFiles()` sweep through a closure prepared outside timing. This
isolates collection cost and guarantees that collection actually runs, with 0%, 50%,
or 100% expired entries. It excludes the random trigger and the accompanying `set()`.
Expiry fixtures replace the expiration in the cache-file header with a past timestamp, without sleeps. This fixture setup
intentionally depends on this package's current file layout and expiry mechanism;
update it if those internals change.

## Filesystem and runtime

The default fixture root is `benchmarks/runtime`, on the checkout's filesystem.
Choose a different filesystem with an existing, writable absolute path:

```sh
CACHE_FILE_BENCH_DIR=/path/on/target/filesystem composer benchmark
```

Run baseline and candidate on the same filesystem, mount options, PHP version,
dependency versions, and machine. Disable Xdebug, profiling, and coverage extensions.
CLI OPcache is disabled in `phpbench.json.dist` for consistency; keep any overrides
identical across comparisons. Keep unrelated disk activity low.

These are **single-process, warm filesystem-cache measurements**. Fixture seeding
warms the OS page cache; clearing PHP's stat cache does not evict the OS cache.
Directory creation is included only in `benchSetNewWithDirectories`. This suite does
not measure cold-disk latency, crash durability, or concurrent reader/writer contention.

## Comparing changes

Focus on a scenario and use more samples:

```sh
composer benchmark -- --filter='benchSet(New|Overwrite)$' --variant=1KiB-level1 --iterations=15 --store --tag=before
# Apply the implementation change, retaining the same benchmark files and dependencies.
composer benchmark -- --filter='benchSet(New|Overwrite)$' --variant=1KiB-level1 --iterations=15 --store --tag=after --ref=before
```

Results are stored in the ignored `.phpbench` directory. Use distinct tags for later
comparisons. Repeat in alternating before/after order to expose filesystem and
background-load drift. The report includes mean, estimated mode, and relative standard deviation (`rstdev`). Inspect the
individual samples before interpreting a small difference. Avoid automatic retries
that selectively discard noisy samples and avoid fixed timing gates on shared CI.
A one-iteration smoke run checks execution and fixtures, not performance.
