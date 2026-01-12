<?php

namespace Mike\PhpredisHashKeyFuzzer\Support;

final class DeterministicRng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & 0x7FFFFFFF;
        if ($this->state === 0) {
            $this->state = 1;
        }
    }

    public function nextInt(int $min, int $max): int
    {
        if ($min > $max) {
            throw new \InvalidArgumentException('min must not exceed max');
        }

        $this->advance();
        $span = $max - $min + 1;
        $value = $this->state / 0x80000000;

        return (int) ($min + floor($value * $span));
    }

    public function nextFloat(): float
    {
        $this->advance();

        return $this->state / 0x80000000;
    }

    public function chance(float $probability): bool
    {
        return $this->nextFloat() < $probability;
    }

    /**
     * @template T
     * @param array<int|string, T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        if (empty($items)) {
            throw new \RuntimeException('Cannot pick from empty set');
        }

        $index = $this->nextInt(0, count($items) - 1);
        $values = array_values($items);

        return $values[$index];
    }

    private function advance(): void
    {
        $this->state = (int) ((1103515245 * $this->state + 12345) & 0x7FFFFFFF);
    }
}
