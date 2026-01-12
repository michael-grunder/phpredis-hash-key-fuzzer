<?php

namespace Mike\PhpredisHashKeyFuzzer\Harness;

final class ResultComparator
{
    private ?array $recordsA = null;
    private ?array $recordsB = null;

    public function __construct(private readonly string $pathA, private readonly string $pathB)
    {
    }

    public function compare(): array
    {
        [$recordsA, $recordsB] = $this->getResultSets();

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
     * @return array{countA: int, countB: int, samples: array<int, array{index: int, a: array<string, mixed>|null, b: array<string, mixed>|null}>}
     */
    public function describeMatchSummary(int $sampleLimit = 3): array
    {
        [$recordsA, $recordsB] = $this->getResultSets();
        $limit = min($sampleLimit, count($recordsA), count($recordsB));
        $samples = [];
        for ($i = 0; $i < $limit; $i++) {
            $samples[] = [
                'index' => $i,
                'a' => $recordsA[$i] ?? null,
                'b' => $recordsB[$i] ?? null,
            ];
        }

        return [
            'countA' => count($recordsA),
            'countB' => count($recordsB),
            'samples' => $samples,
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

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function getResultSets(): array
    {
        if ($this->recordsA === null) {
            $this->recordsA = $this->loadResults($this->pathA);
        }
        if ($this->recordsB === null) {
            $this->recordsB = $this->loadResults($this->pathB);
        }

        return [$this->recordsA, $this->recordsB];
    }
}
