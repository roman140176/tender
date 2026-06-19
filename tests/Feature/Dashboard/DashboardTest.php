<?php

use App\Enums\RelevanceLevel;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\UserRole;
use App\Models\CompanyTender;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\SourceCollectRun;
use App\Models\Tender;
use App\Models\User;
use Database\Seeders\SourceSeeder;
use Inertia\Testing\AssertableInertia as Assert;

it('гость не имеет доступа к дашборду', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('дашборд отдаёт все props company-scoped', function () {
    $admin = adminWithPlan();
    $companyId = $admin->company_id;

    // 2 закупки: одна high (приоритет + динамика), одна medium (релевантная, не high).
    $highTender = Tender::factory()->create([
        'title' => 'Высокий приоритет',
        'region' => 'Москва',
        'price' => '1000000.00',
        'deadline_at' => now()->addDays(5),
    ]);
    $mediumTender = Tender::factory()->create([
        'deadline_at' => now()->addDays(20),
    ]);
    // Закупка с истёкшим дедлайном — НЕ должна попасть в upcomingDeadlines.
    $expiredTender = Tender::factory()->create([
        'deadline_at' => now()->subDays(1),
    ]);

    CompanyTender::factory()->create([
        'company_id' => $companyId,
        'tender_id' => $highTender->id,
        'best_score' => 90,
        'best_level' => RelevanceLevel::High,
        'first_matched_at' => now()->subDays(2),
    ]);
    CompanyTender::factory()->create([
        'company_id' => $companyId,
        'tender_id' => $mediumTender->id,
        'best_score' => 65,
        'best_level' => RelevanceLevel::Medium,
        'first_matched_at' => now()->subDays(1),
    ]);
    CompanyTender::factory()->create([
        'company_id' => $companyId,
        'tender_id' => $expiredTender->id,
        'best_score' => 50,
        'best_level' => RelevanceLevel::Weak,
        'first_matched_at' => now()->subDays(1),
    ]);

    $profile = SearchProfile::factory()->create(['company_id' => $companyId]);
    RadarRun::create([
        'company_id' => $companyId,
        'search_profile_id' => $profile->id,
        'trigger' => RunTrigger::Manual,
        'status' => RunStatus::Ok,
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(9),
        'processed_count' => 100,
        'matched_count' => 3,
        'notified_count' => 2,
        'error_count' => 0,
    ]);

    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();
    SourceCollectRun::create([
        'source_id' => $source->id,
        'company_id' => $companyId,
        'trigger' => RunTrigger::Import,
        'status' => RunStatus::Error,
        'started_at' => now()->subMinutes(20),
        'finished_at' => now()->subMinutes(19),
        'fetched_count' => 10,
        'new_count' => 0,
        'updated_count' => 0,
        'error_count' => 4,
        'log' => 'parse error',
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('counters.new', 3)
            ->where('counters.relevant', 2) // high + medium
            ->has('highPriority', 1)
            ->where('highPriority.0.tender.title', 'Высокий приоритет')
            ->where('highPriority.0.best_score', 90)
            ->has('upcomingDeadlines', 2) // high + medium, истёкший исключён
            ->where('upcomingDeadlines.0.tender.title', 'Высокий приоритет') // ближайший дедлайн первым
            ->has('recentRuns.radar', 1)
            ->has('recentRuns.collect', 1)
            ->where('recentRuns.collect.0.error_count', 4)
            ->where('recentRuns.collect.0.updated_count', 0)
            ->has('dynamics', 30) // окно заполнено нулями
        );
});

it('дашборд изолирует данные между компаниями (tenant-изоляция §13)', function () {
    $adminA = adminWithPlan();
    $adminB = adminWithPlan();

    $tenderA = Tender::factory()->create(['deadline_at' => now()->addDays(3)]);
    $tenderB = Tender::factory()->create(['deadline_at' => now()->addDays(3)]);

    CompanyTender::factory()->create([
        'company_id' => $adminA->company_id,
        'tender_id' => $tenderA->id,
        'best_score' => 95,
        'best_level' => RelevanceLevel::High,
        'first_matched_at' => now()->subDays(1),
    ]);
    CompanyTender::factory()->create([
        'company_id' => $adminB->company_id,
        'tender_id' => $tenderB->id,
        'best_score' => 95,
        'best_level' => RelevanceLevel::High,
        'first_matched_at' => now()->subDays(1),
    ]);

    // Компания A видит только своё.
    $this->actingAs($adminA)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('counters.new', 1)
            ->has('highPriority', 1)
            ->where('highPriority.0.tender.id', $tenderA->id)
            ->has('upcomingDeadlines', 1)
            ->where('upcomingDeadlines.0.tender.id', $tenderA->id)
        );
});

it('суперадмин получает пустой company-scoped дашборд', function () {
    $superadmin = User::factory()->create(['role' => UserRole::Superadmin, 'company_id' => null]);

    $this->actingAs($superadmin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('counters.new', 0)
            ->where('counters.relevant', 0)
            ->has('highPriority', 0)
            ->has('upcomingDeadlines', 0)
            ->has('recentRuns.radar', 0)
            ->has('recentRuns.collect', 0)
            ->has('dynamics', 30)
        );
});
