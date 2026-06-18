<?php

namespace App\Console\Commands;

use App\Enums\RunTrigger;
use App\Jobs\CollectSourceJob;
use App\Models\Source;
use Illuminate\Console\Command;

class CollectSourceCommand extends Command
{
    protected $signature = 'collect:source {code}';

    protected $description = 'Запустить сбор тендеров из источника по коду (dispatch CollectSourceJob)';

    public function handle(): int
    {
        $code = $this->argument('code');
        $source = Source::where('code', $code)->first();

        if ($source === null) {
            $this->error("Источник с кодом «{$code}» не найден.");

            return self::FAILURE;
        }

        CollectSourceJob::dispatch($source->id, RunTrigger::Manual->value);

        $this->info("CollectSourceJob для источника «{$source->name}» (id={$source->id}) поставлен в очередь.");

        return self::SUCCESS;
    }
}
