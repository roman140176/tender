<?php

use App\Services\Import\Canonicalizer;

it('канонизирует строку: lower, trim, схлопывание пробелов, ё→е', function () {
    expect(Canonicalizer::text("  Поставка   МЕТАЛЛОКОНСТРУКЦИЙ\u{00A0} зелёных "))
        ->toBe('поставка металлоконструкций зеленых');
});

it('детерминированный content_hash независим от регистра и пробелов', function () {
    $a = Canonicalizer::contentHash('Поставка кранов', 'ООО «Ромашка»', '7701234567', '2026-06-01', '1500000.00');
    $b = Canonicalizer::contentHash('  поставка   кранов', 'ооо «ромашка»', '7701234567', '2026-06-01', '1500000.00');
    $c = Canonicalizer::contentHash('Поставка кранов', 'ООО «Ромашка»', '7701234567', '2026-06-01', '1500001.00');

    expect($a)->toBe($b)->and($a)->not->toBe($c)->and(strlen($a))->toBe(64);
});

it('null-поля хешируются стабильно', function () {
    $a = Canonicalizer::contentHash('X', null, null, null, null);
    $b = Canonicalizer::contentHash('X', null, null, null, null);
    expect($a)->toBe($b);
});
