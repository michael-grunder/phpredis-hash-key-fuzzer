#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Mike\PhpredisHashKeyFuzzer\Runner\Normalizer;
use Mike\PhpredisHashKeyFuzzer\Support\Options;

const EXIT_OK = 0;
const EXIT_USAGE = 2;
const EXIT_INFRA = 3;

try {
    $opts = Options::parse($argv, [
        'ext' => ['type' => 'string', 'required' => true],
        'host' => ['type' => 'string', 'default' => '127.0.0.1'],
        'port' => ['type' => 'int', 'default' => 6379],
        'db' => ['type' => 'int', 'default' => 0],
        'auth' => ['type' => 'string', 'default' => ''],
        'job' => ['type' => 'string', 'required' => true],
        'out' => ['type' => 'string', 'required' => true],
        'timeout-ms' => ['type' => 'int', 'default' => 2000],
    ]);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(EXIT_USAGE);
}

if (!extension_loaded('redis')) {
    fwrite(STDERR, "Redis extension is not loaded.\n");
    exit(EXIT_INFRA);
}

$timeoutSeconds = max(0.001, $opts['timeout-ms'] / 1000);
$redis = new Redis();
if (!$redis->connect($opts['host'], (int)$opts['port'], $timeoutSeconds)) {
    fwrite(STDERR, "Unable to connect to Redis {$opts['host']}:{$opts['port']}\n");
    exit(EXIT_INFRA);
}
$redis->setOption(Redis::OPT_READ_TIMEOUT, $timeoutSeconds);
if ($opts['auth'] !== '' && !$redis->auth($opts['auth'])) {
    fwrite(STDERR, "Redis AUTH failed\n");
    exit(EXIT_INFRA);
}
if (!$redis->select((int)$opts['db'])) {
    fwrite(STDERR, "Redis SELECT {$opts['db']} failed\n");
    exit(EXIT_INFRA);
}
if (!$redis->flushDB()) {
    fwrite(STDERR, "FLUSHDB failed\n");
    exit(EXIT_INFRA);
}

$job = loadJob($opts['job']);
$normalizer = new Normalizer();
$fh = fopen($opts['out'], 'wb');
if (!$fh) {
    fwrite(STDERR, "Unable to open {$opts['out']} for writing\n");
    exit(EXIT_INFRA);
}

$meta = [
    't' => 'meta',
    'seed' => $job['meta']['seed'] ?? '',
    'ext' => $opts['ext'],
    'php' => PHP_VERSION,
    'redis' => $redis->info()['redis_version'] ?? 'unknown',
    'db' => $opts['db'],
];
fwrite($fh, json_encode($meta, JSON_UNESCAPED_UNICODE) . "\n");

foreach ($job['ops'] as $record) {
    $result = [
        't' => 'res',
        'i' => $record['i'],
        'op' => $record['op'],
        'ok' => true,
        'ret' => null,
        'err' => null,
    ];

    try {
        $ret = executeOp($redis, $record);
        $result['ret'] = $normalizer->normalize($ret);
    } catch (Throwable $e) {
        $result['ok'] = false;
        $result['err'] = [
            'type' => get_class($e),
            'msg' => $e->getMessage(),
            'code' => (int)$e->getCode(),
        ];
    }

    fwrite($fh, json_encode($result, JSON_UNESCAPED_UNICODE) . "\n");
}

fclose($fh);
exit(EXIT_OK);

function loadJob(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "Job file {$path} does not exist\n");
        exit(EXIT_INFRA);
    }

    $fh = fopen($path, 'rb');
    if (!$fh) {
        fwrite(STDERR, "Unable to open job file {$path}\n");
        exit(EXIT_INFRA);
    }

    $meta = null;
    $ops = [];
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            fwrite(STDERR, "Invalid job line: {$line}\n");
            exit(EXIT_INFRA);
        }
        if (($decoded['t'] ?? '') === 'meta') {
            $meta = $decoded;
            continue;
        }
        if (($decoded['t'] ?? '') === 'op') {
            $ops[] = $decoded;
        }
    }
    fclose($fh);

    if ($meta === null) {
        fwrite(STDERR, "Job missing metadata\n");
        exit(EXIT_INFRA);
    }

    return ['meta' => $meta, 'ops' => $ops];
}

function executeOp(Redis $redis, array $record): mixed
{
    return match ($record['op']) {
        'MSET' => opMset($redis, $record['args']),
        'MGET' => opMget($redis, $record['args']),
        'HMSET' => opHmset($redis, $record['args']),
        'HMGET' => opHmget($redis, $record['args']),
        'DEL' => opDel($redis, $record['args']),
        'EXISTS' => opExists($redis, $record['args']),
        'TYPE' => opType($redis, $record['args']),
        'PING' => $redis->ping(),
        default => throw new RuntimeException("Unknown operation {$record['op']}"),
    };
}

function opMset(Redis $redis, array $args): mixed
{
    $assoc = [];
    foreach ($args['kvs'] as $pair) {
        $assoc[(string)$pair[0]] = (string)$pair[1];
    }
    return $redis->mset($assoc);
}

function opMget(Redis $redis, array $args): mixed
{
    $keys = array_map(static fn ($k) => (string)$k, $args['keys']);
    return $redis->mget($keys);
}

function opHmset(Redis $redis, array $args): mixed
{
    $hash = (string)$args['hash'];
    $assoc = [];
    foreach ($args['kvs'] as $pair) {
        $assoc[(string)$pair[0]] = (string)$pair[1];
    }
    return $redis->hMset($hash, $assoc);
}

function opHmget(Redis $redis, array $args): mixed
{
    $hash = (string)$args['hash'];
    $fields = array_map(static fn ($f) => (string)$f, $args['fields']);
    return $redis->hMget($hash, $fields);
}

function opDel(Redis $redis, array $args): mixed
{
    $keys = array_map(static fn ($k) => (string)$k, $args['keys']);
    return $keys === [] ? 0 : $redis->del(...$keys);
}

function opExists(Redis $redis, array $args): mixed
{
    $keys = array_map(static fn ($k) => (string)$k, $args['keys']);
    return $keys === [] ? 0 : $redis->exists(...$keys);
}

function opType(Redis $redis, array $args): mixed
{
    return $redis->type((string)$args['key']);
}
