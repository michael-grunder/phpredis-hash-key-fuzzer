phpredis numeric-string differential fuzzer
==========================================

This harness generates deterministic jobs that hammer the phpredis extension with
numeric-looking strings (leading zeros, signed variants, exponent-style tokens,
UTF-8 digits, and junk combinations) and checks for behavioral deltas between two
extension builds. Each job is replayed twice (extension A vs B) against a clean
Redis database, and the normalized result streams are compared record-by-record.

Prerequisites
-------------
- PHP CLI (8.1+ recommended)
- Redis server reachable at the host/port you pass to the scripts
- Two phpredis shared objects to compare (e.g. `ext/redis-master.so`)

Composer autoload is already generated via `./composer dump-autoload`.

Generating jobs directly
------------------------
```
php producer.php \
    --seed 123 \
    --ops 500 \
    --keyspace 200 \
    --hashspace 50 \
    --fields 50 \
    --values 200 \
    --max-keys-per-op 32 \
    --max-fields-per-op 32 \
    --max-set-per-op 32 \
    --out ./artifacts/sample.job.jsonl
```

Running a single job
--------------------
```
php --no-php-ini -d extension=/path/to/phpredis.so runner.php \
    --ext /path/to/phpredis.so \
    --host 127.0.0.1 --port 6379 --db 0 \
    --job ./artifacts/sample.job.jsonl \
    --out ./artifacts/run.res.jsonl \
    --timeout-ms 2000
```

Differential harness
--------------------
```
php harness.php \
    --phpredis-a ./ext/phpredis-a.so \
    --phpredis-b ./ext/phpredis-b.so \
    --seed 42 \
    --ops 2000 \
    --keyspace 500 \
    --hashspace 200 \
    --fields 200 \
    --values 200 \
    --max-keys-per-op 64 \
    --max-fields-per-op 64 \
    --max-set-per-op 32 \
    --host 127.0.0.1 --port 6379 --db 0 \
    --timeout-ms 2000 \
    --outdir ./artifacts
```

The harness will:
1. Generate a deterministic job file (unless `--job` is supplied).
2. Execute it against both extensions via `runner.php` (each run flushes Redis).
3. Compare the normalized JSONL result streams.

Artifacts (job, `{A,B}.res.jsonl`, `diff.txt`) are stored in the chosen outdir.
Use `--keep-on-pass` to retain them on successful runs. On a mismatch, the harness
exits with code `1` and prints replay commands you can run manually.

Minimal run (defaults)
----------------------
Need the fastest sanity check? Only the extension paths and a seed (or a job path) are required:
```
php harness.php \
    --phpredis-a ./ext/phpredis-master.so \
    --phpredis-b ./ext/phpredis-pr.so \
    --seed quickcheck
```
All other flags fall back to the baked-in defaults shown above:
- `127.0.0.1:6379`, database `0`, no auth
- 500 operations over 200 keys / 50 hashes / 50 fields / 200 values
- Max 32 keys/fields per op, 32 values per SET
- 2000 ms runner timeout
- Artifacts dropped into `./artifacts` (auto-created, wiped on pass unless `--keep-on-pass`)
