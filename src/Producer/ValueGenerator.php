<?php

namespace Mike\PhpredisHashKeyFuzzer\Producer;

use Mike\PhpredisHashKeyFuzzer\Support\DeterministicRng;

final class ValueGenerator
{
    public function __construct(private readonly DeterministicRng $rng)
    {
    }

    public function next(): string
    {
        $pick = $this->rng->nextInt(0, 4);
        return match ($pick) {
            0 => $this->randomAscii(),
            1 => $this->numericish(),
            2 => $this->binaryish(),
            default => $this->shortPhrase(),
        };
    }

    private function randomAscii(): string
    {
        $len = $this->rng->nextInt(1, 32);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr($this->rng->nextInt(32, 126));
        }

        return $out;
    }

    private function numericish(): string
    {
        $generator = new NumericStringGenerator($this->rng);
        return $generator->next();
    }

    private function binaryish(): string
    {
        $len = $this->rng->nextInt(1, 16);
        $bytes = '';
        for ($i = 0; $i < $len; $i++) {
            $bytes .= chr($this->rng->nextInt(0, 255));
        }

        return base64_encode($bytes);
    }

    private function shortPhrase(): string
    {
        $phrases = [
            'alpha',
            'beta',
            'gamma',
            'delta',
            'epsilon',
            'numeric-string',
            'fuzz',
            'redis',
        ];

        $phrase = $phrases[$this->rng->nextInt(0, count($phrases) - 1)];
        if ($this->rng->chance(0.3)) {
            $phrase .= ':' . $this->rng->nextInt(0, 10000);
        }

        return $phrase;
    }
}
