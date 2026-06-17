<?php

namespace Database\Seeders;

use App\Adapters\Eis\EisAdapter;
use App\Models\Source;
use Illuminate\Database\Seeder;

class SourceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            [
                'code' => 'eis',
                'name' => 'ЕИС / zakupki.gov.ru',
                'base_url' => 'https://zakupki.gov.ru',
                'connection_type' => 'open_data',
                'adapter_class' => EisAdapter::class,
                'schedule_cron' => '0 */4 * * *',
            ],
            [
                'code' => 'manual',
                'name' => 'Ручной импорт',
                'base_url' => null,
                'connection_type' => 'manual',
                'adapter_class' => null,
                'schedule_cron' => null,
            ],
        ] as $source) {
            Source::updateOrCreate(['code' => $source['code']], $source);
        }
    }
}
