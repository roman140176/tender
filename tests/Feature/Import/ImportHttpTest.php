<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Source;
use App\Models\SourceCollectRun;
use App\Models\Tender;
use App\Models\User;
use Database\Seeders\SourceSeeder;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(SourceSeeder::class));

it('страница импорта с историей ранов компании', function () {
    $admin = adminWithPlan();
    SourceCollectRun::create([
        'source_id' => Source::where('code', 'manual')->value('id'),
        'company_id' => $admin->company_id,
        'trigger' => 'import', 'status' => 'ok', 'started_at' => now(),
    ]);
    SourceCollectRun::create([ // чужой ран — не должен попасть
        'source_id' => Source::where('code', 'manual')->value('id'),
        'company_id' => Company::factory()->create()->id,
        'trigger' => 'import', 'status' => 'ok', 'started_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('imports.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Imports/Index')
            ->has('runs', 1));
});

it('загрузка CSV создаёт закупки и ран', function () {
    $admin = adminWithPlan();
    $file = new UploadedFile(base_path('tests/Fixtures/import.csv'), 'закупки.csv', 'text/csv', test: true);

    $this->actingAs($admin)
        ->post(route('imports.store'), ['file' => $file])
        ->assertRedirect(route('imports.index', absolute: false))
        ->assertSessionHas('error');

    expect(Tender::count())->toBe(2)
        ->and(SourceCollectRun::where('company_id', $admin->company_id)->count())->toBe(1);
});

it('импорт без ошибок флашит success', function () {
    $admin = adminWithPlan();
    $clean = UploadedFile::fake()->createWithContent('clean.csv',
        "Название;Цена;Закон\nПоставка щебня;500000;44-ФЗ\n");

    $this->actingAs($admin)
        ->post(route('imports.store'), ['file' => $clean])
        ->assertSessionHas('success');
});

it('отклоняет неподдерживаемый формат и большой файл', function () {
    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->post(route('imports.store'), ['file' => UploadedFile::fake()->create('virus.exe', 10)])
        ->assertSessionHasErrors('file');

    $this->actingAs($admin)
        ->post(route('imports.store'), ['file' => UploadedFile::fake()->create('big.csv', 6 * 1024)])
        ->assertSessionHasErrors('file');
});

it('менеджеру запрещено', function () {
    $admin = adminWithPlan();
    $manager = User::factory()->for($admin->company)->create(['role' => UserRole::Manager]);

    $this->actingAs($manager)->get(route('imports.index'))->assertForbidden();
});

it('шаблон CSV скачивается', function () {
    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->get(route('imports.template'))
        ->assertOk()
        ->assertDownload('tender-import-template.csv');
});
