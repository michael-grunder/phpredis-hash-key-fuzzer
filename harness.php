#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Mike\PhpredisHashKeyFuzzer\Harness\ResultComparator;
use Mike\PhpredisHashKeyFuzzer\Producer\JobProducer;
use Mike\PhpredisHashKeyFuzzer\Support\Options;

const EXIT_OK = 0;
const EXIT_MISMATCH = 1;
const EXIT_USAGE = 2;
const EXIT_INFRA = 3;

try {
    $opts = Options::parse($argv, [
        'phpredis-a' => ['type' => 'string', 'required' => true],
        'phpredis-b' => ['type' => 'string', 'required' => true],
        'php' => ['type' => 'string', 'default' => PHP_BINARY],
        'host' => ['type' => 'string', 'default' => '127.0.0.1'],
        'port' => ['type' => 'int', 'default' => 6379],
        'db' => ['type' => 'int', 'default' => 0],
        'auth' => ['type' => 'string', 'default' => ''],
        'seed' => ['type' => 'string'],
        'ops' => ['type' => 'int', 'default' => 500],
        'keyspace' => ['type' => 'int', 'default' => 200],
        'hashspace' => ['type' => 'int', 'default' => 50],
        'fields' => ['type' => 'int', 'default' => 50],
        'values' => ['type' => 'int', 'default' => 200],
        'max-keys-per-op' => ['type' => 'int', 'default' => 32],
        'max-fields-per-op' => ['type' => 'int', 'default' => 32],
        'max-set-per-op' => ['type' => 'int', 'default' => 32],
        'timeout-ms' => ['type' => 'int', 'default' => 2000],
        'job' => ['type' => 'string', 'default' => ''],
        'outdir' => ['type' => 'string', 'default' => __DIR__ . '/artifacts'],
        'keep-on-pass' => ['type' => 'bool'],
    ]);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(EXIT_USAGE);
}

if ($opts['job'] === '' && !isset($opts['seed'])) {
    fwrite(STDERR, "Error: --seed is required unless --job is provided\n");
    exit(EXIT_USAGE);
}

$opts['phpredis-a'] = resolve_existing_path($opts['phpredis-a']);
$opts['phpredis-b'] = resolve_existing_path($opts['phpredis-b']);
$opts['php'] = resolve_existing_path($opts['php']);

$outDir = resolve_path($opts['outdir']);
$opts['outdir'] = $outDir;
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Error: unable to create artifacts directory {$outDir}\n");
    exit(EXIT_INFRA);
}

$jobPath = $opts['job'];
$jobProvided = $jobPath !== '';
if (!$jobProvided) {
    $seedName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)$opts['seed']);
    $seedName = $seedName !== '' ? $seedName : 'seed';
    $jobPath = sprintf('%s/job-%s.jsonl', rtrim($outDir, '/'), $seedName);
    $producer = new JobProducer($opts);
    $producer->produce($jobPath);
} else {
    $jobPath = resolve_existing_path($jobPath);
}

$resultA = $outDir . '/A.res.jsonl';
$resultB = $outDir . '/B.res.jsonl';

try {
    runRunner($opts, $opts['phpredis-a'], $jobPath, $resultA, 'A', $opts['php']);
    runRunner($opts, $opts['phpredis-b'], $jobPath, $resultB, 'B', $opts['php']);
} catch (RuntimeException $e) {
    fwrite(STDERR, "Runner failure: {$e->getMessage()}\n");
    exit(EXIT_INFRA);
}

$comparator = new ResultComparator($resultA, $resultB);
$comparison = $comparator->compare();
if (!$comparison['match']) {
    $mismatchJob = $outDir . '/mismatch.job.jsonl';
    if (!copy($jobPath, $mismatchJob)) {
        fwrite(STDERR, "Warning: unable to copy job to {$mismatchJob}\n");
    }
    $jobOp = null;
    if ($comparison['index'] !== null) {
        $jobOp = loadJobOperation($mismatchJob, $comparison['index']);
    }
    $diff = formatDiff($comparison, $jobOp);
    $diffPath = $outDir . '/diff.txt';
    file_put_contents($diffPath, $diff . "\n\n" . replayHints($opts, $mismatchJob));
    fwrite(STDERR, $diff . "\n");
    fwrite(STDERR, "Job/result artifacts available in {$outDir}\n");
    exit(EXIT_MISMATCH);
}

if (!$jobProvided && !$opts['keep-on-pass']) {
    @unlink($jobPath);
    @unlink($resultA);
    @unlink($resultB);
    @unlink($outDir . '/diff.txt');
}

fwrite(STDOUT, "No mismatches detected.\n");
exit(EXIT_OK);

function runRunner(array $opts, string $extPath, string $jobPath, string $outPath, string $label, string $phpBinary): void
{
    if (!is_file($extPath)) {
        throw new RuntimeException("Extension {$extPath} not found");
    }

    $cmd = buildRunnerCommand($opts, $extPath, $jobPath, $outPath, $phpBinary);
    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptor, $pipes, __DIR__);
    if (!is_resource($process)) {
        throw new RuntimeException("Failed to start runner {$label}");
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Runner {$label} failed with exit code {$exitCode}: {$stderr}{$stdout}");
    }
}

function buildRunnerCommand(array $opts, string $extPath, string $jobPath, string $outPath, string $phpBinary): string
{
    $args = [
        '--ext', $extPath,
        '--host', $opts['host'],
        '--port', (string)$opts['port'],
        '--db', (string)$opts['db'],
        '--job', $jobPath,
        '--out', $outPath,
        '--timeout-ms', (string)$opts['timeout-ms'],
    ];

    if ($opts['auth'] !== '') {
        $args[] = '--auth';
        $args[] = $opts['auth'];
    }

    $parts = [
        escapeshellarg($phpBinary),
        '--no-php-ini',
        '-d',
        'extension=' . escapeshellarg($extPath),
        escapeshellarg(__DIR__ . '/runner.php'),
    ];

    foreach ($args as $arg) {
        $parts[] = escapeshellarg($arg);
    }

    return implode(' ', $parts);
}

function formatDiff(array $comparison, ?array $jobOp): string
{
    $lines = [];
    $index = $comparison['index'];
    $lines[] = $index === null ? 'Mismatch detected while comparing result streams' : "Mismatch at op index {$index}";
    if ($jobOp !== null) {
        $lines[] = sprintf('Operation %s (job index %d)', $jobOp['op'] ?? 'unknown', $jobOp['i'] ?? -1);
        $lines[] = 'Arguments:';
        $lines[] = json_encode($jobOp['args'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($index !== null) {
        $lines[] = 'Operation details unavailable (see mismatch.job.jsonl for full job)';
    }
    $lines[] = 'Result diff:';
    foreach (describeResultDiff($comparison['a'], $comparison['b']) as $diffLine) {
        $lines[] = '  - ' . $diffLine;
    }
    $lines[] = '=== Extension A ===';
    $lines[] = json_encode($comparison['a'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $lines[] = '=== Extension B ===';
    $lines[] = json_encode($comparison['b'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return implode("\n", $lines);
}

/**
 * @return array<int, string>
 */
function describeResultDiff(?array $a, ?array $b): array
{
    if ($a === null && $b === null) {
        return ['No result records found for either extension'];
    }
    if ($a === null) {
        return ['Extension A has no result for this op'];
    }
    if ($b === null) {
        return ['Extension B has no result for this op'];
    }

    $lines = buildDiffLines($a, $b, 'result');
    if ($lines === []) {
        return ['Result payloads differ but no field-level differences were detected'];
    }

    return $lines;
}

/**
 * @return array<int, string>
 */
function buildDiffLines(mixed $a, mixed $b, string $path): array
{
    if ($a === $b) {
        return [];
    }

    if (is_array($a) && is_array($b)) {
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        $lines = [];
        foreach ($keys as $key) {
            $childPath = formatDiffPath($path, $key);
            $hasA = array_key_exists($key, $a);
            $hasB = array_key_exists($key, $b);
            if (!$hasA) {
                $lines[] = sprintf('%s missing in extension A (B=%s)', $childPath, stringifyValue($b[$key]));
                continue;
            }
            if (!$hasB) {
                $lines[] = sprintf('%s missing in extension B (A=%s)', $childPath, stringifyValue($a[$key]));
                continue;
            }
            $lines = array_merge($lines, buildDiffLines($a[$key], $b[$key], $childPath));
        }

        return $lines;
    }

    return [sprintf('%s: A=%s B=%s', $path, stringifyValue($a), stringifyValue($b))];
}

function formatDiffPath(string $base, int|string $segment): string
{
    if ($base === '') {
        return (string)$segment;
    }

    if (is_int($segment)) {
        return "{$base}[{$segment}]";
    }

    return "{$base}.{$segment}";
}

function stringifyValue(mixed $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return '(unencodable value)';
    }

    return $encoded;
}

function loadJobOperation(string $jobPath, int $targetIndex): ?array
{
    if (!is_file($jobPath)) {
        return null;
    }

    $fh = fopen($jobPath, 'rb');
    if (!$fh) {
        return null;
    }

    try {
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            if (($decoded['t'] ?? null) !== 'op') {
                continue;
            }
            if ((int)($decoded['i'] ?? -1) === $targetIndex) {
                return $decoded;
            }
        }
    } finally {
        fclose($fh);
    }

    return null;
}

function replayHints(array $opts, string $jobPath): string
{
    $base = sprintf(
        "%s --no-php-ini -d extension=%%s %s --ext %%s --host %s --port %d --db %d --job %s --out %%s --timeout-ms %d",
        escapeshellarg($opts['php']),
        escapeshellarg(__DIR__ . '/runner.php'),
        escapeshellarg($opts['host']),
        $opts['port'],
        $opts['db'],
        escapeshellarg($jobPath),
        $opts['timeout-ms'],
    );

    if ($opts['auth'] !== '') {
        $base .= ' --auth ' . escapeshellarg($opts['auth']);
    }

    $hint = [];
    $hint[] = 'Replay commands:';
    $hint[] = sprintf($base, escapeshellarg($opts['phpredis-a']), escapeshellarg($opts['phpredis-a']), escapeshellarg($opts['outdir'] . '/A.res.jsonl'));
    $hint[] = sprintf($base, escapeshellarg($opts['phpredis-b']), escapeshellarg($opts['phpredis-b']), escapeshellarg($opts['outdir'] . '/B.res.jsonl'));

    return implode("\n", $hint);
}

function resolve_path(string $path): string
{
    if ($path === '') {
        return getcwd();
    }
    if ($path[0] === DIRECTORY_SEPARATOR) {
        return $path;
    }

    return rtrim(getcwd(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}

function resolve_existing_path(string $path): string
{
    $real = realpath($path);
    if ($real !== false) {
        return $real;
    }

    $candidate = resolve_path($path);
    if (file_exists($candidate)) {
        return $candidate;
    }

    fwrite(STDERR, "Error: file {$path} not found\n");
    exit(EXIT_USAGE);
}
