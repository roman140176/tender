<?php

namespace App\Services\Import;

use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

class SpreadsheetImport implements OnEachRow, WithChunkReading, WithCustomCsvSettings, WithHeadingRow
{
    /** @param array<string, string> $headerMap */
    public function __construct(
        private readonly ImportRowCollector $collector,
        private readonly array $headerMap,
        private readonly int $maxRows,
    ) {}

    public function onRow(Row $row): void
    {
        if ($this->collector->fetched >= $this->maxRows) {
            throw new NormalizationException('Слишком много строк: максимум '.$this->maxRows.'.');
        }

        $raw = $row->toArray(null, true);
        $mapped = [];

        foreach ($raw as $header => $value) {
            $key = $this->headerMap[Canonicalizer::text((string) $header)] ?? null;
            if ($key !== null) {
                $mapped[$key] = $value;
            }
        }

        // полностью пустые строки пропускаем без счёта
        if (array_filter($mapped, fn ($v) => trim((string) $v) !== '') === []) {
            return;
        }

        $this->collector->accept($mapped, $row->getIndex());
    }

    public function chunkSize(): int
    {
        return (int) config('import.chunk_size');
    }

    /** @return array<string, string> */
    public function getCsvSettings(): array
    {
        return ['delimiter' => ';', 'input_encoding' => 'UTF-8'];
    }
}
