<?php

return [
    // Дефолтные веса §7.3. Частичный override — search_profiles.scoring_weights (мержится поверх).
    // deadline_far_days/deadline_soon_days — пороги в днях (не баллы), тоже переопределяемы.
    'weights' => [
        'exact_in_title' => 30,
        'exact_in_description' => 15,
        'keyword_in_title' => 20,
        'keyword_in_description' => 15,
        'high_priority_bonus' => 10,
        'low_priority_score' => 2,
        'negative_keyword' => -20,
        'price_above_min' => 20,
        'price_below_min' => -10,
        'priority_region' => 10,
        'deadline_far_bonus' => 10,
        'deadline_soon_penalty' => -20,
        'deadline_far_days' => 5,
        'deadline_soon_days' => 2,
        'okpd_match' => 5,
        'source_closed' => -30,
    ],
];
