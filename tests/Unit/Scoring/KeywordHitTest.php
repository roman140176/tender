<?php

use App\Enums\KeywordType;
use App\Scoring\KeywordHit;

it('matched() истинно при ненулевом поле', function () {
    $hit = new KeywordHit('краны', KeywordType::Main, 'title');

    expect($hit->matched())->toBeTrue()
        ->and($hit->field)->toBe('title')
        ->and($hit->type)->toBe(KeywordType::Main);
});

it('matched() ложно, когда ключ не найден', function () {
    $hit = new KeywordHit('насосы', KeywordType::Broad, null);

    expect($hit->matched())->toBeFalse();
});
