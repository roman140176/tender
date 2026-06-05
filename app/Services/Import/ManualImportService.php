<?php

namespace App\Services\Import;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Company;
use App\Models\Source;
use App\Models\SourceCollectRun;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

class ManualImportService
{
    public function __construct(
        private readonly Normalizer $normalizer,
        private readonly Deduplicator $deduplicator,
    ) {}

    public function import(UploadedFile $file, Company $company): SourceCollectRun
    {
        set_time_limit(300); // синхронный импорт до 5000 строк может не уложиться в 30с FPM-дефолта

        $source = Source::query()->where('code', 'manual')->firstOrFail();

        $run = SourceCollectRun::create([
            'source_id' => $source->id,
            'company_id' => $company->id,
            'trigger' => RunTrigger::Import,
            'status' => RunStatus::Running,
            'started_at' => now(),
        ]);

        $collector = new ImportRowCollector($this->normalizer, $this->deduplicator, $source->id, $company, $run->id);

        try {
            match ($this->detectFormat($file)) {
                'json' => $this->importJson($file, $collector),
                default => $this->importSpreadsheet($file, $collector), // csv и xlsx — единый путь maatwebsite
            };

            $run->update([
                'status' => RunStatus::Ok,
                'finished_at' => now(),
                'fetched_count' => $collector->fetched,
                'new_count' => $collector->new,
                'updated_count' => $collector->updated,
                'error_count' => $collector->errors,
                'log' => $collector->errorLines !== [] ? implode("\n", array_slice($collector->errorLines, 0, 100)) : null,
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => RunStatus::Error,
                'finished_at' => now(),
                'log' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }

        // A4: здесь диспатчится MatchProfileJob для активных профилей компании (trigger=import).

        return $run->refresh();
    }

    private function detectFormat(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension()) === 'json' ? 'json' : 'spreadsheet';
    }

    private function importJson(UploadedFile $file, ImportRowCollector $collector): void
    {
        $rows = json_decode((string) file_get_contents($file->getRealPath()), true);

        if (! is_array($rows)) {
            throw new NormalizationException('JSON должен содержать массив объектов закупок.');
        }

        if (count($rows) > config('import.max_rows')) {
            throw new NormalizationException('Слишком много строк: максимум '.config('import.max_rows').'.');
        }

        foreach (array_values($rows) as $i => $row) {
            $collector->accept(is_array($row) ? $row : [], $i + 1);
        }
    }

    private function importSpreadsheet(UploadedFile $file, ImportRowCollector $collector): void
    {
        $import = new SpreadsheetImport($collector, config('import.header_map'), config('import.max_rows'));
        Excel::import($import, $file);
    }
}
