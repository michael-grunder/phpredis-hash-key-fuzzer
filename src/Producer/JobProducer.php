<?php

namespace Mike\PhpredisHashKeyFuzzer\Producer;

use Mike\PhpredisHashKeyFuzzer\Support\DeterministicRng;

final class JobProducer
{
    private NumericStringGenerator $keyGenerator;
    private ValueGenerator $valueGenerator;

    private array $options;
    private DeterministicRng $rng;

    /** @var array<int, string> */
    private array $keys = [];
    /** @var array<int, string> */
    private array $hashes = [];
    /** @var array<int, string> */
    private array $fields = [];
    /** @var array<int, string> */
    private array $values = [];

    /** @var array<string, string> */
    private array $knownKeys = [];
    /** @var array<string, array<string, string>> */
    private array $knownHashFields = [];

    public function __construct(array $options)
    {
        $seed = self::normalizeSeed($options['seed']);
        $this->rng = new DeterministicRng($seed);
        $this->options = $options;
        $this->keyGenerator = new NumericStringGenerator($this->rng);
        $this->valueGenerator = new ValueGenerator($this->rng);

        $this->keys = $this->buildPool((int)$options['keyspace'], fn () => $this->keyGenerator->next());
        $this->hashes = $this->buildPool((int)$options['hashspace'], fn () => $this->keyGenerator->next());
        $this->fields = $this->buildPool((int)$options['fields'], fn () => $this->keyGenerator->next());
        $this->values = $this->buildPool((int)$options['values'], fn () => $this->valueGenerator->next());
    }

    public function produce(string $outPath): void
    {
        $fh = fopen($outPath, 'wb');
        if (!$fh) {
            throw new \RuntimeException("Unable to open {$outPath} for writing");
        }

        $meta = [
            't' => 'meta',
            'seed' => (string)$this->options['seed'],
            'ops' => (int)$this->options['ops'],
            'ver' => 1,
            'note' => 'numeric-string differential fuzz',
        ];
        fwrite($fh, $this->encode($meta));

        for ($i = 0; $i < (int)$this->options['ops']; $i++) {
            $opRecord = $this->buildOperation($i);
            fwrite($fh, $this->encode($opRecord));
        }

        fclose($fh);
    }

    private static function normalizeSeed(string $seed): int
    {
        $hash = hash('sha256', $seed, true);
        $value = 0;
        for ($i = 0; $i < 8; $i++) {
            $value = ($value << 8) | ord($hash[$i]);
        }
        return $value === 0 ? 1 : $value;
    }

    /**
     * @param int $count
     * @param callable():string $producer
     * @return array<int, string>
     */
    private function buildPool(int $count, callable $producer): array
    {
        $pool = [];
        while (count($pool) < $count) {
            $candidate = $producer();
            $pool[$candidate] = $candidate;
        }

        return array_values($pool);
    }

    private function encode(array $record): string
    {
        return json_encode($record, JSON_UNESCAPED_UNICODE) . "\n";
    }

    private function buildOperation(int $index): array
    {
        $weights = [
            'HMSET' => 25,
            'HMGET' => 25,
            'MSET' => 20,
            'MGET' => 20,
            'DEL' => 5,
            'EXISTS' => 3,
            'TYPE' => 1,
            'PING' => 1,
        ];

        $op = $this->weightedPick($weights);
        $args = match ($op) {
            'HMSET' => $this->opHmset(),
            'HMGET' => $this->opHmget(),
            'MSET' => $this->opMset(),
            'MGET' => $this->opMget(),
            'DEL' => $this->opDel(),
            'EXISTS' => $this->opExists(),
            'TYPE' => $this->opType(),
            default => $this->opPing(),
        };

        return [
            't' => 'op',
            'i' => $index,
            'op' => $op,
            'args' => $args,
        ];
    }

    private function weightedPick(array $weights): string
    {
        $sum = array_sum($weights);
        $target = $this->rng->nextInt(1, $sum);
        $acc = 0;
        foreach ($weights as $name => $weight) {
            $acc += $weight;
            if ($target <= $acc) {
                return $name;
            }
        }

        return array_key_first($weights);
    }

    private function randomKeys(int $min, int $max): array
    {
        $count = $this->rng->nextInt($min, $max);
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $kind = $this->rng->nextInt(0, 99);
            if ($kind < 50 && !empty($this->knownKeys)) {
                $keys[] = $this->rng->pick(array_values($this->knownKeys));
            } elseif ($kind < 80) {
                $keys[] = $this->rng->pick($this->keys);
            } else {
                $keys[] = $this->keyGenerator->next();
            }

            if ($this->rng->chance(0.15) && !empty($keys)) {
                $keys[] = $this->rng->pick($keys);
            }
        }

        return $keys;
    }

    private function randomFieldsForHash(string $hash, int $min, int $max): array
    {
        $count = $this->rng->nextInt($min, $max);
        $fields = [];
        $knownValues = array_values($this->knownHashFields[$this->token($hash)] ?? []);
        for ($i = 0; $i < $count; $i++) {
            $kind = $this->rng->nextInt(0, 99);
            if ($kind < 40 && !empty($knownValues)) {
                $fields[] = $this->rng->pick($knownValues);
            } elseif ($kind < 70) {
                $fields[] = $this->rng->pick($this->fields);
            } else {
                $fields[] = $this->keyGenerator->next();
            }

            if ($this->rng->chance(0.2) && !empty($fields)) {
                $fields[] = $this->rng->pick($fields);
            }
        }

        return $fields;
    }

    private function opMset(): array
    {
        $count = $this->rng->nextInt(1, (int)$this->options['max-set-per-op']);
        $kvs = [];
        for ($i = 0; $i < $count; $i++) {
            $key = $this->rng->pick($this->keys);
            if ($this->rng->chance(0.2) && !empty($kvs)) {
                $key = $this->rng->pick(array_column($kvs, 0));
            }
            $value = $this->rng->pick($this->values);
            $kvs[] = [$key, $value];
            $this->knownKeys[$this->token($key)] = $key;
        }

        return [
            'kvs' => $kvs,
        ];
    }

    private function opMget(): array
    {
        $keys = $this->randomKeys(1, (int)$this->options['max-keys-per-op']);
        return [
            'keys' => $keys,
        ];
    }

    private function opHmset(): array
    {
        $hash = $this->rng->pick($this->hashes);
        if ($this->rng->chance(0.2)) {
            $hash = $this->rng->pick($this->keys);
        }

        $count = $this->rng->nextInt(1, (int)$this->options['max-set-per-op']);
        $kvs = [];
        for ($i = 0; $i < $count; $i++) {
            $field = $this->rng->pick($this->fields);
            if ($this->rng->chance(0.2) && !empty($kvs)) {
                $field = $this->rng->pick(array_column($kvs, 0));
            }
            $value = $this->rng->pick($this->values);
            $kvs[] = [$field, $value];
            $this->rememberHashField($hash, $field);
        }

        return [
            'hash' => $hash,
            'kvs' => $kvs,
        ];
    }

    private function opHmget(): array
    {
        $hash = $this->rng->pick($this->hashes);
        if ($this->rng->chance(0.3)) {
            $hash = $this->rng->pick($this->keys);
        }

        $fields = $this->randomFieldsForHash($hash, 1, (int)$this->options['max-fields-per-op']);

        return [
            'hash' => $hash,
            'fields' => $fields,
        ];
    }

    private function opDel(): array
    {
        $keys = $this->randomKeys(1, min((int)$this->options['max-set-per-op'], 16));
        foreach ($keys as $key) {
            unset($this->knownKeys[$this->token($key)], $this->knownHashFields[$this->token($key)]);
        }

        return [
            'keys' => $keys,
        ];
    }

    private function opExists(): array
    {
        return [
            'keys' => $this->randomKeys(1, 8),
        ];
    }

    private function opType(): array
    {
        $key = $this->rng->chance(0.5) && !empty($this->knownKeys)
            ? $this->rng->pick(array_values($this->knownKeys))
            : $this->rng->pick($this->keys);

        return [
            'key' => $key,
        ];
    }

    private function opPing(): array
    {
        return [];
    }

    private function token(string $value): string
    {
        return strlen($value) . ':' . $value;
    }

    private function rememberHashField(string $hash, string $field): void
    {
        $hashToken = $this->token($hash);
        if (!isset($this->knownHashFields[$hashToken])) {
            $this->knownHashFields[$hashToken] = [];
        }
        $this->knownHashFields[$hashToken][$this->token($field)] = $field;
    }
}
