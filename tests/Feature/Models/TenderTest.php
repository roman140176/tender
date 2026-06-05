<?php

use App\Models\Source;
use App\Models\Tender;
use Illuminate\Database\QueryException;

it('заполняет search_vector и находит по русской морфологии', function () {
    Tender::factory()->create([
        'title' => 'Поставка металлоконструкций для моста',
        'description' => 'Изготовление по чертежам КМД',
    ]);

    $found = Tender::whereRaw("search_vector @@ plainto_tsquery('russian', ?)", ['металлоконструкции'])->exists();
    expect($found)->toBeTrue();
});

it('запрещает дубль по (source_id, external_id)', function () {
    $source = Source::factory()->create();
    Tender::factory()->create(['source_id' => $source->id, 'external_id' => 'X-1']);
    Tender::factory()->create(['source_id' => $source->id, 'external_id' => 'X-1']);
})->throws(QueryException::class);

it('не позволяет массово назначить search_vector', function () {
    $tender = Tender::factory()->make();
    expect($tender->isGuarded('search_vector'))->toBeTrue();
});
