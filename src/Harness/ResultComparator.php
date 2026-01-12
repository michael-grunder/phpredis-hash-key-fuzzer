<?php

namespace Mike\PhpredisHashKeyFuzzer\Harness;

final class ResultComparator
{
    public function __construct(private readonly string $pathA, private readonly string $pathB)
    {
    }

    public function compare(): array
    {
        $recordsA = $this->loadResults($this->pathA);
        $recordsB = $this->loadResults($this->pathB);

        $count = max(count($recordsA), count($recordsB));
        for ($i = 0; $i < $count; $i++) {
            $rowA = $recordsA[$i] ?? null;
            $rowB = $recordsB[$i] ?? null;
            if ($rowA !== $rowB) {
                return [
                    'match' => false,
                    'index' => $i,
                    'a' => $rowA,
                    'b' => $rowB,
                ];
            }
        }

        return [
            'match' => true,
            'index' => null,
            'a' => null,
            'b' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadResults(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Result file {$path} does not exist");
        }
        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new \RuntimeException("Unable to open {$path}");
        }

        $records = [];
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException("Invalid JSON line in {$path}");
            }
            if (($decoded['t'] ?? null) !== 'res') {
                continue;
            }
            $records[] = $decoded;
        }
        fclose($fh);

        return $records;
    }
}
