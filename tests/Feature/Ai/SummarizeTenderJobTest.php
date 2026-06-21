<?php

use App\Ai\Providers\FakeLlmClient;
use App\Jobs\SummarizeTenderJob;
use App\Models\AiSummary;
use App\Models\AiUsage;
use App\Models\Company;
use App\Models\Tender;
use App\Services\Ai\TenderSummarizer;
use Illuminate\Support\Facades\Bus;

function jobSummarizer(FakeLlmClient $fake): TenderSummarizer
{
    return new TenderSummarizer($fake);
}

it('джоб создаёт саммари, повторный запуск пропускает (идемпотентность)', function () {
    $company = Company::factory()->create();
    $tender = Tender::factory()->create();
    $fake = FakeLlmClient::returning(json_encode(['summary' => 's', 'requirements' => [], 'risks' => []]), prompt: 10, completion: 10);

    (new SummarizeTenderJob((int) $tender->id, (int) $company->id))->handle(jobSummarizer($fake));
    (new SummarizeTenderJob((int) $tender->id, (int) $company->id))->handle(jobSummarizer($fake));

    expect(AiSummary::where('tender_id', $tender->id)->count())->toBe(1)
        ->and($fake->calls)->toHaveCount(1); // второй раз LLM не дёргался

    $usage = AiUsage::withoutTenancy()->where('company_id', $company->id)->first();
    expect((int) $usage->calls)->toBe(1);
});

it('джоб уникален по tenderId (ShouldBeUnique) — защита от двойного диспатча', function () {
    $job = new SummarizeTenderJob(42, 7);

    expect($job)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('42');
});

it('джоб — no-op, если тендер удалён между диспатчем и обработкой', function () {
    $company = Company::factory()->create();
    $fake = FakeLlmClient::returning('{}');

    (new SummarizeTenderJob(999999, (int) $company->id))->handle(jobSummarizer($fake));

    expect($fake->calls)->toHaveCount(0)
        ->and(AiSummary::count())->toBe(0);
});

it('команда ai:summarize ставит джоб в очередь', function () {
    Bus::fake();
    $company = Company::factory()->create();
    $tender = Tender::factory()->create();

    $this->artisan('ai:summarize', ['tender' => $tender->id, '--company' => $company->id])
        ->assertExitCode(0);

    Bus::assertDispatched(
        SummarizeTenderJob::class,
        fn (SummarizeTenderJob $job) => $job->tenderId === (int) $tender->id && $job->companyId === (int) $company->id
    );
});

it('команда падает на несуществующем тендере', function () {
    $company = Company::factory()->create();

    $this->artisan('ai:summarize', ['tender' => 999999, '--company' => $company->id])
        ->assertFailed();
});
