#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Mike\PhpredisHashKeyFuzzer\Producer\JobProducer;
use Mike\PhpredisHashKeyFuzzer\Support\Options;

const EXIT_OK = 0;
const EXIT_USAGE = 2;

try {
    $opts = Options::parse($argv, [
        'seed' => ['type' => 'string', 'required' => true],
        'ops' => ['type' => 'int', 'default' => 500],
        'keyspace' => ['type' => 'int', 'default' => 200],
        'hashspace' => ['type' => 'int', 'default' => 50],
        'fields' => ['type' => 'int', 'default' => 50],
        'values' => ['type' => 'int', 'default' => 200],
        'max-keys-per-op' => ['type' => 'int', 'default' => 32],
        'max-fields-per-op' => ['type' => 'int', 'default' => 32],
        'max-set-per-op' => ['type' => 'int', 'default' => 32],
        'out' => ['type' => 'string', 'required' => true],
    ]);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(EXIT_USAGE);
}

$producer = new JobProducer($opts);
$producer->produce($opts['out']);
fwrite(STDOUT, "Job written to {$opts['out']}\n");
exit(EXIT_OK);
