# File cache benchmarks

Install development dependencies with `composer install`, then run:

```sh
composer benchmark
```

The suite uses [PHPBench](https://phpbench.readthedocs.io/en/latest/annotributes.html)
with seven independent samples per scenario. To run each scenario once:

```sh
composer benchmark:smoke
```

## Scenarios and units

| Benchmark | Work per reported sample |
| --- | --- |
| `OperationsBench` | 100 single-key calls, or one `*Multiple()` call with 100 keys |
| `WorkloadBench` | 100 interleaved calls: 95/5, 80/20, or 50/50 read/write ratios |
| `MaintenanceBench` | One `clear()` or garbage collection (GC) run over 100 or 1,000 entries |

The scenarios cover hits, missing keys, expired entries, `has()`, new writes, overwrites,
deletes, writes with immediate expiry, and operations on multiple keys. New writes run with
existing directories and with directories created during the write. Values include 1 KiB and
64 KiB `string` values and a structured `array`. The 1 KiB scenarios compare directory levels 0, 1, and 2.
Missing-key scenarios use existing directories. Mixed workloads read existing values and overwrite
existing keys. They exclude application work and the cost of rebuilding missing values.

**Reported times are per batch.** Divide operation and workload times by 100 to get the
average time per key. Maintenance times cover the complete `clear()` or GC run.
Timing includes loops and result recording. Data preparation, result comparisons, and cleanup
run outside the timed section. PHPBench memory figures include prepared data and recorded results;
they do not measure only the memory allocated by the cache operation.

## Preparing data and running samples

Each sample runs in a separate PHP process through PHPBench's default remote executor.
It uses its own randomly named cache directory. Keys, values, and operation order are fixed.
Setup checks that prepared entries can be read. After timing, the suite checks return values
and stored values. Maintenance scenarios also check that deleted files are gone.
Cleanup removes the sample's directory, including when a handled PHP error occurs.
A terminated process can leave `sample-*` directories in the benchmark root.

Each benchmark method runs **once per sample, without warmup calls**. It already contains
a batch of operations. Repeating an insert, delete, or clear on the same data changes what
is measured. The suite fails if `--revs` or `--warmup` causes a method to run twice.
Increase `--iterations` to collect more samples. Keep the default executor so each sample
has separate setup and cleanup.

Normal scenarios disable automatic GC. The GC benchmark calls the private `removeCacheFiles()`
method through a closure prepared before timing. It measures collection with 0%, 50%, or 100%
expired entries. It excludes the random GC trigger and the `set()` call that normally triggers it.
To prepare expired entries without waiting, setup writes a past expiry time into each cache file's
header. Update this setup if the file format or expiry rules change.

## Filesystem and runtime

The default data directory is `benchmarks/runtime`, on the checkout's filesystem.
Choose a different filesystem with an existing, writable absolute path:

```sh
CACHE_FILE_BENCH_DIR=/path/on/target/filesystem composer benchmark
```

Compare both versions on the same machine and filesystem, with the same mount options,
PHP version, and dependency versions. Disable Xdebug, profiling, and coverage extensions.
CLI OPcache is disabled in `phpbench.json.dist` for consistency; keep any overrides
identical across comparisons. Keep unrelated disk activity low.

These measurements use **one process with data already in the operating system's file cache**.
Preparing entries loads their data into that cache. Clearing PHP's stat cache does not clear it.
Directory creation is timed only in `benchSetNewWithDirectories`. The suite does not measure
uncached disk reads, data survival after a crash, or delays caused by concurrent readers and writers.

## Comparing changes

Focus on a scenario and use more samples:

```sh
composer benchmark -- --filter='benchSet(New|Overwrite)$' --variant=1KiB-level1 --iterations=15 --store --tag=before
# Apply the implementation change, retaining the same benchmark files and dependencies.
composer benchmark -- --filter='benchSet(New|Overwrite)$' --variant=1KiB-level1 --iterations=15 --store --tag=after --ref=before
```

Results are stored in the ignored `.phpbench` directory. Use distinct tags for later
comparisons. Alternate the order of the two versions to reduce the effect of changes in system load.
The report includes the mean, estimated mode, and relative standard deviation (`rstdev`).
Inspect individual samples before drawing conclusions from a small difference. Keep slow samples
in the results and avoid fixed performance limits on shared CI machines.
Use the smoke command to check that scenarios run correctly. One sample is insufficient to compare performance.
