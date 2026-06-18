<?php

namespace App\Services\Import;

use App\Models\Company;
use App\Models\Tender;
use Illuminate\Database\QueryException;

class ImportRowCollector
{
    public int $fetched = 0;

    public int $new = 0;

    public int $updated = 0;

    public int $errors = 0;

    /** @var list<string> */
    public array $errorLines = [];

    /** @var list<int> */
    public array $unchangedIds = [];

    public function __construct(
        private readonly Normalizer $normalizer,
        private readonly Deduplicator $deduplicator,
        private readonly int $sourceId,
        private readonly Company $company,
        private readonly ?int $collectRunId,
    ) {}

    /** @param array<string, mixed> $row  английские ключи */
    public function accept(array $row, int $lineNumber): void
    {
        $this->fetched++;

        try {
            $normalized = $this->normalizer->normalize($row);
            $result = $this->deduplicator->process($normalized, $this->sourceId, $this->company->id, $this->collectRunId);

            match ($result->status) {
                'created' => $this->new++,
                'updated' => $this->updated++,
                'unchanged' => $this->unchangedIds[] = $result->tender->id,
                default => null,
            };
        } catch (NormalizationException $e) {
            $this->errors++;
            $this->errorLines[] = "строка {$lineNumber}: {$e->getMessage()}";
        } catch (QueryException) {
            $this->errors++;
            $this->errorLines[] = "строка {$lineNumber}: конфликт данных (повторите импорт)";
        }
    }

    /**
     * Батч-обновление last_seen_at для unchanged-строк одним UPDATE.
     * Вызывать после завершения обработки файла/чанка.
     */
    public function flushLastSeen(): void
    {
        if ($this->unchangedIds === []) {
            return;
        }

        Tender::whereIn('id', $this->unchangedIds)->update(['last_seen_at' => now()]);
        $this->unchangedIds = [];
    }
}
