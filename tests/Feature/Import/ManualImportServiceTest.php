<?php

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Company;
use App\Models\SourceCollectRun;
use App\Models\Tender;
use App\Models\TenderChange;
use App\Services\Import\ManualImportService;
use Database\Seeders\SourceSeeder;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(fn () => $this->seed(SourceSeeder::class));

it('импортирует CSV: новые, дубль внутри файла, ошибка строки', function () {
    $company = Company::factory()->create();
    $file = new UploadedFile(base_path('tests/Fixtures/import.csv'), 'import.csv', 'text/csv', test: true);

    $run = app(ManualImportService::class)->import($file, $company);

    expect($run->status)->toBe(RunStatus::Ok)
        ->and($run->trigger)->toBe(RunTrigger::Import)
        ->and($run->company_id)->toBe($company->id)
        ->and($run->fetched_count)->toBe(4)
        ->and($run->new_count)->toBe(2)      // дубль склеен, строка без названия — ошибка
        ->and($run->error_count)->toBe(1)
        ->and($run->log)->toContain('строка 4'); // нумерация с учётом заголовка

    expect(Tender::count())->toBe(2)
        ->and(Tender::whereNull('owner_company_id')->count())->toBe(0);
});

it('повторный импорт того же CSV не создаёт дублей', function () {
    $company = Company::factory()->create();
    $file = fn () => new UploadedFile(base_path('tests/Fixtures/import.csv'), 'import.csv', 'text/csv', test: true);

    app(ManualImportService::class)->import($file(), $company);
    $run2 = app(ManualImportService::class)->import($file(), $company);

    expect($run2->new_count)->toBe(0)->and(Tender::count())->toBe(2);
});

it('импортирует JSON с английскими ключами', function () {
    $company = Company::factory()->create();
    $file = new UploadedFile(base_path('tests/Fixtures/import.json'), 'import.json', 'application/json', test: true);

    $run = app(ManualImportService::class)->import($file, $company);

    expect($run->new_count)->toBe(2)->and($run->error_count)->toBe(0);
    expect(Tender::where('title', 'Ремонт кровли')->sole()->price)->toBeNull(); // кривая цена → null, не ошибка
});

it('импортирует XLSX', function () {
    $company = Company::factory()->create();

    // генерируем xlsx на лету из CSV-фикстуры
    $rows = array_map(fn ($line) => str_getcsv($line, ';'), file(base_path('tests/Fixtures/import.csv')));
    $export = new class($rows) implements FromArray
    {
        public function __construct(private array $rows) {}

        public function array(): array
        {
            return $this->rows;
        }
    };
    Excel::store($export, 'import-test.xlsx', 'local');
    $path = storage_path('app/private/import-test.xlsx');
    if (! file_exists($path)) {
        $path = storage_path('app/import-test.xlsx');
    }

    $run = app(ManualImportService::class)->import(
        new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', test: true),
        $company,
    );

    expect($run->new_count)->toBe(2)->and(Tender::count())->toBe(2);
});

it('повторный импорт через границу суток не даёт ложных updated', function () {
    $company = Company::factory()->create();
    $file = fn () => new UploadedFile(base_path('tests/Fixtures/import.csv'), 'import.csv', 'text/csv', test: true);

    $this->travelTo(now()->setTime(9, 0));
    app(ManualImportService::class)->import($file(), $company);

    $this->travelTo(now()->addDay()->setTime(17, 42));
    $run2 = app(ManualImportService::class)->import($file(), $company);

    expect($run2->new_count)->toBe(0)
        ->and($run2->updated_count)->toBe(0)
        ->and(TenderChange::count())->toBe(0);
});

it('ран фиксируется даже при пустом файле', function () {
    $company = Company::factory()->create();
    $empty = UploadedFile::fake()->createWithContent('empty.csv', "Название;Цена\n");

    $run = app(ManualImportService::class)->import($empty, $company);

    expect($run->fetched_count)->toBe(0)
        ->and($run->status)->toBe(RunStatus::Ok)
        ->and(SourceCollectRun::count())->toBe(1);
});
