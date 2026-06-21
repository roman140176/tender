<?php

namespace App\Jobs;

use App\Models\AiSummary;
use App\Models\Tender;
use App\Services\Ai\TenderSummarizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Фоновый AI-разбор тендера для компании. Идемпотентен: если саммари по тендеру
 * уже есть — пропускает (не жжёт токены повторно). Учёт расхода — на companyId,
 * который диспатчер обязан передать (usage привязан к компании-инициатору).
 *
 * ShouldBeUnique по tenderId: блокирует параллельный/двойной диспатч для одного
 * тендера, иначе exists()-проверка (TOCTOU) пропустила бы оба прохода → двойной
 * вызов платного LLM и двойной учёт usage.
 */
class SummarizeTenderJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** Срок блокировки уникальности (с) — чтобы зависший воркер не держал лок вечно. */
    public int $uniqueFor = 3600;

    public function __construct(
        public int $tenderId,
        public int $companyId,
    ) {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /** Ключ уникальности — один незавершённый разбор на тендер. */
    public function uniqueId(): string
    {
        return (string) $this->tenderId;
    }

    public function handle(TenderSummarizer $summarizer): void
    {
        // Идемпотентность: уже разобран — выходим до обращения к LLM.
        if (AiSummary::where('tender_id', $this->tenderId)->exists()) {
            return;
        }

        $tender = Tender::find($this->tenderId);
        if ($tender === null) {
            return; // тендер удалён между диспатчем и обработкой
        }

        $summarizer->summarize($tender, $this->companyId);
    }
}
