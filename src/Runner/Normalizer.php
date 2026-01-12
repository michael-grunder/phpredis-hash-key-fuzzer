<?php

namespace Mike\PhpredisHashKeyFuzzer\Runner;

final class Normalizer
{
    public function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string)$value;
        }

        if (is_float($value)) {
            return $this->normalizeFloat($value);
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            if ($this->isList($value)) {
                $out = [];
                foreach ($value as $item) {
                    $out[] = $this->normalize($item);
                }
                return $out;
            }

            $out = [];
            foreach ($value as $k => $v) {
                $normalizedKey = is_int($k) ? (string)$k : (string)$k;
                $out[$normalizedKey] = $this->normalize($v);
            }

            return $out;
        }

        return (string)$value;
    }

    private function normalizeFloat(float $value): string
    {
        return rtrim(rtrim(sprintf('%.17g', $value), '0'), '.') ?: '0';
    }

    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        $expectedKey = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }

        return true;
    }
}
