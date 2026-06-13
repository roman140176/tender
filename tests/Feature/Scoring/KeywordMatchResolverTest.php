<?php

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Scoring\KeywordHit;
use App\Scoring\KeywordMatchResolver;

function resolveHits(Tender $tender, SearchProfile $profile): array
{
    return app(KeywordMatchResolver::class)->resolve($tender, $profile->keywords()->get());
}

/** @param list<KeywordHit> $hits */
function hitField(array $hits, string $value): ?string
{
    foreach ($hits as $h) {
        if ($h->value === $value) {
            return $h->field;
        }
    }

    return 'НЕ НАЙДЕН';
}

it('non-exact ключ матчится морфологически и относит к названию (приоритет над описанием)', function () {
    $profile = SearchProfile::factory()->create();
    // 'краны' (>4): substring 'краны' НЕ содержится в 'кранов' → попадание в title даёт именно стеммер.
    Keyword::factory()->for($profile)->create(['value' => 'краны', 'type' => KeywordType::Main]);

    $tender = Tender::factory()->create([
        'title' => 'Поставка кранов мостовых', // склонение в названии
        'description' => 'краны дополнительно в описании',
    ]);

    $hits = resolveHits($tender, $profile)['positive'];
    expect(hitField($hits, 'краны'))->toBe('title');
});

it('non-exact ключ только в описании → field=description', function () {
    $profile = SearchProfile::factory()->create();
    Keyword::factory()->for($profile)->create(['value' => 'насосы', 'type' => KeywordType::Broad]);

    $tender = Tender::factory()->create([
        'title' => 'Поставка оборудования',
        'description' => 'комплект насосов промышленных', // стеммер russian: насосов→насос, насосы→насос
    ]);

    expect(hitField(resolveHits($tender, $profile)['positive'], 'насосы'))->toBe('description');
});

it('non-exact ключ не найден → field=null', function () {
    $profile = SearchProfile::factory()->create();
    Keyword::factory()->for($profile)->create(['value' => 'компьютеры', 'type' => KeywordType::Main]);

    $tender = Tender::factory()->create(['title' => 'Ремонт кровли', 'description' => 'кровельные работы']);

    expect(hitField(resolveHits($tender, $profile)['positive'], 'компьютеры'))->toBeNull();
});

it('exact-фраза требует смежного вхождения (нормализованный substring)', function () {
    $profile = SearchProfile::factory()->create();
    Keyword::factory()->for($profile)->create(['value' => 'краны мостовые', 'type' => KeywordType::Exact]);

    $contiguous = Tender::factory()->create(['title' => 'Поставка краны мостовые опорные', 'description' => null]);
    $scattered = Tender::factory()->create(['title' => 'Краны и фермы мостовые', 'description' => null]);

    expect(hitField(resolveHits($contiguous, $profile)['positive'], 'краны мостовые'))->toBe('title')
        ->and(hitField(resolveHits($scattered, $profile)['positive'], 'краны мостовые'))->toBeNull();
});

it('exact-фраза нормализуется (NBSP/регистр/ё)', function () {
    $profile = SearchProfile::factory()->create();
    Keyword::factory()->for($profile)->create(['value' => 'ёлочные  ГИРЛЯНДЫ', 'type' => KeywordType::Exact]);

    $tender = Tender::factory()->create(['title' => 'Закупка елочные гирлянды уличные', 'description' => null]);

    expect(hitField(resolveHits($tender, $profile)['positive'], 'ёлочные  ГИРЛЯНДЫ'))->toBe('title');
});

it('аббревиатура (≤4) ловится substring даже когда стеммер бы её выкинул', function () {
    $profile = SearchProfile::factory()->create();
    Keyword::factory()->for($profile)->create(['value' => 'КМД', 'type' => KeywordType::Main]);

    $tender = Tender::factory()->create(['title' => 'Изготовление КМД', 'description' => null]);

    expect(hitField(resolveHits($tender, $profile)['positive'], 'КМД'))->toBe('title');
});

it('негативные ключи попадают в отдельный список с полевой атрибуцией', function () {
    $profile = SearchProfile::factory()->create();
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);
    Keyword::factory()->for($profile)->create(['value' => 'бывшие в употреблении', 'type' => KeywordType::Negative]);

    $tender = Tender::factory()->create([
        'title' => 'Поставка кранов',
        'description' => 'оборудование бывшие в употреблении',
    ]);

    $resolved = resolveHits($tender, $profile);
    expect(hitField($resolved['positive'], 'кран'))->toBe('title')
        ->and(hitField($resolved['negative'], 'бывшие в употреблении'))->toBe('description');
});
