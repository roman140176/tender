<?php

use App\Models\CompanySource;
use App\Models\Source;
use Database\Seeders\SourceSeeder;
use Illuminate\Support\Facades\DB;

it('сидирует реестр источников', function () {
    $this->seed(SourceSeeder::class);
    expect(Source::pluck('code')->all())->toContain('eis', 'manual');
});

it('шифрует клиентский config', function () {
    $cs = CompanySource::factory()->create(['config' => ['apiKey' => 'secret-123']]);

    $raw = DB::table('company_sources')->where('id', $cs->id)->value('config');
    expect($raw)->not->toContain('secret-123')
        ->and($cs->fresh()->config)->toBe(['apiKey' => 'secret-123']);
});

it('не отдаёт config в сериализацию', function () {
    $cs = CompanySource::factory()->create(['config' => ['apiKey' => 'secret-123']]);
    $array = $cs->fresh()->toArray();

    expect($array)->not->toHaveKey('config')
        ->and($array['has_config'])->toBeTrue()
        ->and(json_encode($array))->not->toContain('secret-123');
});

it('сидер идемпотентен и не затирает adapter_class', function () {
    $this->seed(SourceSeeder::class);
    Source::where('code', 'eis')->update(['adapter_class' => 'App\\Adapters\\EisAdapter']);

    $this->seed(SourceSeeder::class);

    expect(Source::count())->toBe(2)
        ->and(Source::where('code', 'eis')->value('adapter_class'))->toBe('App\\Adapters\\EisAdapter');
});
