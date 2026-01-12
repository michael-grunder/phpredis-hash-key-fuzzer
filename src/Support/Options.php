<?php

namespace Mike\PhpredisHashKeyFuzzer\Support;

final class Options
{
    /**
     * @param array<int, string> $argv
     * @param array<string, array<string, mixed>> $spec
     * @return array<string, mixed>
     */
    public static function parse(array $argv, array $spec): array
    {
        $opts = [];
        $argc = count($argv);
        $i = 1;

        while ($i < $argc) {
            $arg = $argv[$i];

            if (!str_starts_with($arg, '--')) {
                throw new \InvalidArgumentException("Unexpected positional argument '{$arg}'");
            }

            $nameValue = substr($arg, 2);
            $valuePart = null;

            if (strpos($nameValue, '=') !== false) {
                [$nameValue, $valuePart] = explode('=', $nameValue, 2);
            }

            if (!array_key_exists($nameValue, $spec)) {
                throw new \InvalidArgumentException("Unknown option --{$nameValue}");
            }

            $definition = $spec[$nameValue];
            $type = $definition['type'] ?? 'string';

            if ($type === 'bool') {
                if ($valuePart !== null) {
                    throw new \InvalidArgumentException("Option --{$nameValue} does not allow a value");
                }

                $opts[$nameValue] = true;
                $i++;
                continue;
            }

            $value = $valuePart;
            if ($value === null) {
                $i++;
                if ($i >= $argc) {
                    throw new \InvalidArgumentException("Option --{$nameValue} requires a value");
                }
                $value = $argv[$i];
            }

            $opts[$nameValue] = self::castValue($value, $type, $nameValue);
            $i++;
        }

        foreach ($spec as $name => $definition) {
            if (!array_key_exists($name, $opts)) {
                if (array_key_exists('default', $definition)) {
                    $opts[$name] = $definition['default'];
                    continue;
                }

                if (!empty($definition['required'])) {
                    throw new \InvalidArgumentException("Missing required option --{$name}");
                }

                if (($definition['type'] ?? '') === 'bool') {
                    $opts[$name] = false;
                }
            }
        }

        return $opts;
    }

    private static function castValue(string $value, string $type, string $name): mixed
    {
        return match ($type) {
            'int' => self::castInt($value, $name),
            'float' => self::castFloat($value, $name),
            default => $value,
        };
    }

    private static function castInt(string $value, string $name): int
    {
        if (!preg_match('/^-?\d+$/', $value)) {
            throw new \InvalidArgumentException("Option --{$name} expects an integer, got '{$value}'");
        }

        return (int) $value;
    }

    private static function castFloat(string $value, string $name): float
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("Option --{$name} expects a number, got '{$value}'");
        }

        return (float) $value;
    }
}
