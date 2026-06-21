<?php

namespace App\Console\Commands;

use App\Jobs\SummarizeTenderJob;
use App\Models\Company;
use App\Models\Tender;
use Illuminate\Console\Command;

/**
 * Ручной запуск AI-разбора тендера: ставит SummarizeTenderJob в очередь.
 * Usage пишется на компанию-инициатора, поэтому --company обязателен.
 */
class SummarizeTenderCommand extends Command
{
    protected $signature = 'ai:summarize {tender : ID тендера} {--company= : ID компании-инициатора (для учёта usage)}';

    protected $description = 'Поставить в очередь AI-разбор тендера (SummarizeTenderJob)';

    public function handle(): int
    {
        $tenderId = (int) $this->argument('tender');
        if (Tender::find($tenderId) === null) {
            $this->error("Тендер id={$tenderId} не найден.");

            return self::FAILURE;
        }

        $companyOpt = $this->option('company');
        if (! is_scalar($companyOpt) || (string) $companyOpt === '') {
            $this->error('Укажите --company=<id> — usage привязан к компании.');

            return self::FAILURE;
        }
        $companyId = (int) $companyOpt;
        if (Company::find($companyId) === null) {
            $this->error("Компания id={$companyId} не найдена.");

            return self::FAILURE;
        }

        SummarizeTenderJob::dispatch($tenderId, $companyId);
        $this->info("SummarizeTenderJob поставлен в очередь: тендер={$tenderId}, компания={$companyId}.");

        return self::SUCCESS;
    }
}
