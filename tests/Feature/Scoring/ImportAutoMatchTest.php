<?php

use App\Enums\KeywordType;
use App\Enums\RunTrigger;
use App\Jobs\MatchProfileJob;
use App\Models\Company;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\TenderMatch;
use App\Scoring\MatchProfileService;
use App\Services\Import\ManualImportService;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Source::factory()->create(['code' => 'manual']);
});

function importCsv(Company $company): void
{
    app(TenantContext::class)->set($company->id);
    $csv = "название;цена;ссылка\nПоставка кранов мостовых;1500000;https://example.com/t/1\n";
    $file = UploadedFile::fake()->createWithContent('import.csv', $csv);
    app(ManualImportService::class)->import($file, $company);
    app(TenantContext::class)->set(null); // воркер исполняет MatchProfileJob без auth-контекста — воспроизводим это
}

it('диспатчит MatchProfileJob только для активных профилей компании с trigger=import', function () {
    Queue::fake();

    $company = Company::factory()->create();
    $active = SearchProfile::factory()->for($company)->create(['is_active' => true]);
    $inactive = SearchProfile::factory()->for($company)->create(['is_active' => false]);
    $otherCompany = SearchProfile::factory()->create(['is_active' => true]); // другая компания

    importCsv($company);

    Queue::assertPushed(MatchProfileJob::class, 1);
    Queue::assertPushed(MatchProfileJob::class, fn (MatchProfileJob $job) => $job->searchProfileId === $active->id
        && $job->trigger === RunTrigger::Import->value);
});

it('сквозной: импорт → синхронный матч создаёт TenderMatch', function () {
    Queue::fake(); // перехватываем, потом исполняем вручную — детерминизм без воркера

    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create(['is_active' => true]);
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);

    importCsv($company);

    // Эмулируем обработку очереди.
    Queue::assertPushed(MatchProfileJob::class, function (MatchProfileJob $job) {
        $job->handle(app(MatchProfileService::class));

        return true;
    });

    expect(TenderMatch::withoutTenancy()->where('company_id', $company->id)->count())->toBe(1);
});

it('импорт без активных профилей не диспатчит джобы', function () {
    Queue::fake();
    $company = Company::factory()->create();
    SearchProfile::factory()->for($company)->create(['is_active' => false]);

    importCsv($company);

    Queue::assertNothingPushed();
});
