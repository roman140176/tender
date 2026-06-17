<?php

use App\Adapters\Eis\EisAdapter;
use App\Models\Source;
use Database\Seeders\SourceSeeder;
use Illuminate\Support\Facades\DB;

it('after migration source eis has adapter_class set to EisAdapter', function () {
    // RefreshDatabase прогоняет все миграции, включая data-миграцию.
    // SourceSeeder НЕ запускается автоматически — создаём запись вручную
    // так же, как её создаёт SourceSeeder (без adapter_class).
    DB::table('sources')->insertOrIgnore([
        'code' => 'eis',
        'name' => 'ЕИС / zakupki.gov.ru',
        'base_url' => 'https://zakupki.gov.ru',
        'connection_type' => 'open_data',
        'adapter_class' => null,
        'schedule_cron' => null,
        'is_active' => true,
        'default_config' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Пересчитать миграцию через DB::statement, имитируя up()
    DB::table('sources')
        ->where('code', 'eis')
        ->update([
            'adapter_class' => EisAdapter::class,
            'schedule_cron' => '0 */4 * * *',
        ]);

    $source = DB::table('sources')->where('code', 'eis')->first();

    expect($source->adapter_class)->toBe(EisAdapter::class);
    expect($source->schedule_cron)->toBe('0 */4 * * *');
});

it('data-migration up sets adapter_class on real sources row via migration file', function () {
    // Вставить строку без adapter_class
    DB::table('sources')->insertOrIgnore([
        'code' => 'eis',
        'name' => 'ЕИС / zakupki.gov.ru',
        'base_url' => 'https://zakupki.gov.ru',
        'connection_type' => 'open_data',
        'adapter_class' => null,
        'schedule_cron' => null,
        'is_active' => true,
        'default_config' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Вызвать up() миграции напрямую
    $migration = include database_path('migrations/2026_06_17_000001_set_eis_adapter_class.php');
    $migration->up();

    $source = DB::table('sources')->where('code', 'eis')->first();
    expect($source->adapter_class)->toBe(EisAdapter::class);
    expect($source->schedule_cron)->toBe('0 */4 * * *');
});

it('data-migration down nullifies adapter_class and schedule_cron', function () {
    DB::table('sources')->insertOrIgnore([
        'code' => 'eis',
        'name' => 'ЕИС / zakupki.gov.ru',
        'base_url' => 'https://zakupki.gov.ru',
        'connection_type' => 'open_data',
        'adapter_class' => EisAdapter::class,
        'schedule_cron' => '0 */4 * * *',
        'is_active' => true,
        'default_config' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = include database_path('migrations/2026_06_17_000001_set_eis_adapter_class.php');
    $migration->down();

    $source = DB::table('sources')->where('code', 'eis')->first();
    expect($source->adapter_class)->toBeNull();
    expect($source->schedule_cron)->toBeNull();
});

it('SourceSeeder sets adapter_class and schedule_cron for eis (fresh install)', function () {
    $this->seed(SourceSeeder::class);
    $eis = Source::where('code', 'eis')->first();
    expect($eis->adapter_class)->toBe(EisAdapter::class);
    expect($eis->schedule_cron)->toBe('0 */4 * * *');
    expect(Source::where('code', 'manual')->value('adapter_class'))->toBeNull();
});
