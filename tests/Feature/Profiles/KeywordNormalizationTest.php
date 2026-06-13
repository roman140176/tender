<?php

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\SearchProfile;

it('Keyword::normalizeValue схлопывает пробелы/NBSP и тримит, сохраняя регистр и ё', function () {
    expect(Keyword::normalizeValue("  Ёлочные \u{00A0} ГИРЛЯНДЫ  "))->toBe('Ёлочные ГИРЛЯНДЫ')
        ->and(Keyword::normalizeValue('металлоконструкции'))->toBe('металлоконструкции')
        ->and(Keyword::normalizeValue("кран\tмостовой"))->toBe('кран мостовой')
        ->and(Keyword::normalizeValue("слово\u{00A0}слово"))->toBe('слово слово');
});

it('контроллер сохраняет ключ в нормализованном виде', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);

    $this->actingAs($admin)
        ->post(route('profiles.keywords.store', $profile), ['value' => 'кран  мостовой', 'type' => KeywordType::Main->value])
        ->assertRedirect();

    expect(Keyword::sole()->value)->toBe('кран мостовой');
});

it('unique ловит коллизию нормализованных значений (двойной vs одинарный пробел)', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);
    Keyword::factory()->for($profile)->create(['value' => 'кран мостовой', 'type' => KeywordType::Main]);

    $this->actingAs($admin)
        ->post(route('profiles.keywords.store', $profile), ['value' => 'кран   мостовой', 'type' => KeywordType::Main->value])
        ->assertSessionHasErrors('value');

    expect(Keyword::count())->toBe(1);
});
