<?php

namespace Mike\PhpredisHashKeyFuzzer\Producer;

use Mike\PhpredisHashKeyFuzzer\Support\DeterministicRng;

final class NumericStringGenerator
{
    public function __construct(private readonly DeterministicRng $rng)
    {
    }

    public function next(): string
    {
        $categories = [
            'leading_zero' => 12,
            'signed' => 8,
            'large' => 6,
            'exp' => 6,
            'decimal' => 6,
            'hexish' => 3,
            'whitespace' => 2,
            'junk' => 4,
            'emptyish' => 1,
            'utf8' => 1,
            'prefixed' => 5,
        ];

        $choice = $this->weightedPick($categories);

        return match ($choice) {
            'leading_zero' => $this->leadingZero(),
            'signed' => $this->signed(),
            'large' => $this->large(),
            'exp' => $this->exponent(),
            'decimal' => $this->decimal(),
            'hexish' => $this->hexish(),
            'whitespace' => $this->whitespace(),
            'junk' => $this->junk(),
            'emptyish' => $this->emptyish(),
            'utf8' => $this->utfDigits(),
            'prefixed' => $this->prefixed(),
            default => (string)$choice,
        };
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

    private function digits(int $min, int $max): string
    {
        $length = $this->rng->nextInt($min, $max);
        $digits = '';
        for ($i = 0; $i < $length; $i++) {
            $digits .= (string)$this->rng->nextInt(0, 9);
        }

        return $digits === '' ? '0' : $digits;
    }

    private function leadingZero(): string
    {
        $zeros = str_repeat('0', $this->rng->nextInt(1, 8));
        return $zeros . $this->digits(1, 6);
    }

    private function signed(): string
    {
        $sign = $this->rng->pick(['+', '-']);
        $zeros = $this->rng->chance(0.5) ? str_repeat('0', $this->rng->nextInt(0, 4)) : '';
        return $sign . $zeros . $this->digits(1, 6);
    }

    private function large(): string
    {
        $predefined = [
            '2147483647',
            '2147483648',
            '9223372036854775807',
            '9223372036854775808',
            '18446744073709551615',
        ];

        if ($this->rng->chance(0.3)) {
            return $this->rng->pick($predefined);
        }

        return $this->digits(8, 20);
    }

    private function exponent(): string
    {
        $sign = $this->rng->chance(0.5) ? $this->rng->pick(['+', '-']) : '';
        $base = $this->rng->chance(0.2) ? '0' : (string)$this->rng->nextInt(0, 9);
        $mantissa = $this->rng->chance(0.5) ? '.' . $this->digits(1, 3) : '';
        $expSign = $this->rng->chance(0.3) ? $this->rng->pick(['+', '-']) : '';
        return "{$sign}{$base}{$mantissa}" . $this->rng->pick(['e', 'E']) . "{$expSign}" . $this->digits(1, 4);
    }

    private function decimal(): string
    {
        $left = $this->rng->chance(0.4) ? '' : $this->digits(1, 3);
        $right = $this->digits(1, 4);
        $padLeft = $this->rng->chance(0.5) ? str_repeat('0', $this->rng->nextInt(0, 3)) : '';
        $padRight = $this->rng->chance(0.5) ? str_repeat('0', $this->rng->nextInt(0, 3)) : '';
        $dot = $this->rng->pick(['.', '.']);
        return "{$padLeft}{$left}{$dot}{$right}{$padRight}";
    }

    private function hexish(): string
    {
        $prefix = $this->rng->pick(['0x', '0X', '00x', '00X']);
        $digits = $this->digits(1, 4);
        return $prefix . $digits;
    }

    private function whitespace(): string
    {
        $spacey = $this->rng->pick([" ", "\t", "\n"]);
        return ($this->rng->chance(0.5) ? $spacey : '') . $this->digits(1, 3) . ($this->rng->chance(0.5) ? $spacey : '');
    }

    private function junk(): string
    {
        $samples = ['01a', '1a', '1_000', '00-1', '1+1', '0e123foo', 'xyz', 'k:0e123'];
        if ($this->rng->chance(0.4)) {
            return $this->rng->pick($samples);
        }

        $core = $this->digits(1, 4);
        $suffix = $this->rng->pick(['a', '_foo', '-bar', '+baz']);
        return $core . $suffix;
    }

    private function emptyish(): string
    {
        return $this->rng->chance(0.5) ? '' : ' ';
    }

    private function utfDigits(): string
    {
        $samples = ["１２３", "١٢٣", "𝟘𝟙𝟚", "𝟢𝟣𝟤"];
        return $this->rng->pick($samples);
    }

    private function prefixed(): string
    {
        $prefixes = ['user:', 'tweet:', 'hash:', 'ns:', 'key:'];
        return $this->rng->pick($prefixes) . $this->next();
    }
}
