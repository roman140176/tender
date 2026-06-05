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
    $cs = CompanySource::factory()->create(['config' => '{"apiKey":"secret-123"}']);

    $raw = DB::table('company_sources')->where('id', $cs->id)->value('config');
    expect($raw)->not->toContain('secret-123')
        ->and($cs->fresh()->config)->toBe('{"apiKey":"secret-123"}');
});
