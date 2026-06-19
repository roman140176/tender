<?php

use App\Enums\KeywordType;
use App\Enums\RunTrigger;
use App\Enums\UserRole;
use App\Jobs\MatchProfileJob;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

function activeProfileForCompany(int $companyId): SearchProfile
{
    $profile = SearchProfile::withoutTenancy()->create([
        'company_id' => $companyId,
        'name' => 'Металлоконструкции '.uniqid(),
        'is_active' => true,
        'notification_threshold' => 60,
        'deadline_min_days' => 0,
        'exclude_on_negative_in_title' => false,
    ]);

    Keyword::factory()->create([
        'search_profile_id' => $profile->id,
        'type' => KeywordType::Main->value,
        'value' => 'ферма',
    ]);

    return $profile;
}

it('manage-company пользователь запускает радар по одному направлению', function () {
    Bus::fake();
    $admin = adminWithPlan();
    $profile = activeProfileForCompany($admin->company_id);

    $this->actingAs($admin)
        ->post(route('profiles.run', $profile))
        ->assertRedirect()
        ->assertSessionHas('success');

    Bus::assertDispatched(MatchProfileJob::class, function (MatchProfileJob $job) use ($profile) {
        return $job->searchProfileId === $profile->id
            && $job->trigger === RunTrigger::Manual->value
            && $job->sinceUpdatedAt === null;
    });
});

it('manage-company пользователь запускает радар по всей компании — только активные профили', function () {
    Bus::fake();
    $admin = adminWithPlan();
    $active1 = activeProfileForCompany($admin->company_id);
    $active2 = activeProfileForCompany($admin->company_id);

    $inactive = SearchProfile::withoutTenancy()->create([
        'company_id' => $admin->company_id,
        'name' => 'Выключено '.uniqid(),
        'is_active' => false,
        'notification_threshold' => 60,
        'deadline_min_days' => 0,
        'exclude_on_negative_in_title' => false,
    ]);

    $this->actingAs($admin)
        ->post(route('profiles.run-company'))
        ->assertRedirect()
        ->assertSessionHas('success');

    Bus::assertDispatched(MatchProfileJob::class, 2);
    Bus::assertDispatched(MatchProfileJob::class, fn (MatchProfileJob $j) => $j->searchProfileId === $active1->id);
    Bus::assertDispatched(MatchProfileJob::class, fn (MatchProfileJob $j) => $j->searchProfileId === $active2->id);
    Bus::assertNotDispatched(MatchProfileJob::class, fn (MatchProfileJob $j) => $j->searchProfileId === $inactive->id);
});

it('запуск по компании не диспатчит профили чужой компании (tenant-изоляция)', function () {
    Bus::fake();
    $admin = adminWithPlan();
    $mine = activeProfileForCompany($admin->company_id);

    $other = adminWithPlan();
    $foreign = activeProfileForCompany($other->company_id);

    $this->actingAs($admin)
        ->post(route('profiles.run-company'))
        ->assertRedirect();

    Bus::assertDispatched(MatchProfileJob::class, 1);
    Bus::assertDispatched(MatchProfileJob::class, fn (MatchProfileJob $j) => $j->searchProfileId === $mine->id);
    Bus::assertNotDispatched(MatchProfileJob::class, fn (MatchProfileJob $j) => $j->searchProfileId === $foreign->id);
});

it('per-profile: нельзя запустить чужое направление — 404 (scope скрывает модель)', function () {
    Bus::fake();
    $admin = adminWithPlan();
    $other = adminWithPlan();
    $foreign = activeProfileForCompany($other->company_id);

    $this->actingAs($admin)
        ->post(route('profiles.run', $foreign))
        ->assertNotFound();

    Bus::assertNothingDispatched();
});

it('не-manage-company (менеджер) получает 403 на оба маршрута', function () {
    Bus::fake();
    $admin = adminWithPlan();
    $manager = User::factory()->for($admin->company)->create(['role' => UserRole::Manager]);
    $profile = activeProfileForCompany($admin->company_id);

    $this->actingAs($manager)->post(route('profiles.run', $profile))->assertForbidden();
    $this->actingAs($manager)->post(route('profiles.run-company'))->assertForbidden();

    Bus::assertNothingDispatched();
});

it('гость получает редирект на login', function () {
    Bus::fake();
    $admin = adminWithPlan();
    $profile = activeProfileForCompany($admin->company_id);

    $this->post(route('profiles.run', $profile))->assertRedirect(route('login'));
    $this->post(route('profiles.run-company'))->assertRedirect(route('login'));

    Bus::assertNothingDispatched();
});
