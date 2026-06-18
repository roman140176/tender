<?php

use App\Enums\RunTrigger;
use App\Jobs\CollectMatchFanoutJob;
use App\Jobs\MatchProfileJob;
use App\Models\Company;
use App\Models\SearchProfile;
use Illuminate\Support\Facades\Queue;

it('диспатчит MatchProfileJob для каждого активного профиля всех компаний', function () {
    Queue::fake();

    $company1 = Company::factory()->create();
    $company2 = Company::factory()->create();

    // три активных профиля в двух компаниях
    SearchProfile::factory()->for($company1)->create(['is_active' => true]);
    SearchProfile::factory()->for($company1)->create(['is_active' => true]);
    SearchProfile::factory()->for($company2)->create(['is_active' => true]);
    // один неактивный — не должен попасть
    SearchProfile::factory()->for($company2)->create(['is_active' => false]);

    (new CollectMatchFanoutJob('2026-06-17T10:00:00+00:00'))->handle();

    Queue::assertPushed(MatchProfileJob::class, 3);
    Queue::assertPushed(MatchProfileJob::class, function (MatchProfileJob $job) {
        return $job->trigger === RunTrigger::Schedule->value
            && $job->sinceUpdatedAt === '2026-06-17T10:00:00+00:00';
    });
});

it('не диспатчит ничего при отсутствии активных профилей', function () {
    Queue::fake();

    SearchProfile::factory()->create(['is_active' => false]);

    (new CollectMatchFanoutJob(null))->handle();

    Queue::assertNothingPushed();
});

it('диспатчится самостоятельно с синтаксисом dispatch()', function () {
    Queue::fake();

    CollectMatchFanoutJob::dispatch('2026-06-17T12:00:00+00:00');

    Queue::assertPushed(CollectMatchFanoutJob::class, function (CollectMatchFanoutJob $job) {
        return $job->sinceUpdatedAt === '2026-06-17T12:00:00+00:00';
    });
});
