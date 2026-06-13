<?php

namespace App\Scoring;

use App\Enums\LawType;
use App\Models\SearchProfile;
use App\Models\Tender;

final class HardFilter
{
    /**
     * Жёсткие фильтры §7.1. Непрошедшие не скорятся.
     *
     * @param  list<int>  $enabledSourceIds  включённые у компании источники (+ manual)
     */
    public function passes(Tender $tender, SearchProfile $profile, array $enabledSourceIds): bool
    {
        // Источник ∈ включённых
        if (! in_array((int) $tender->source_id, $enabledSourceIds, true)) {
            return false;
        }

        // Владелец manual-закупки (Р13): приватная закупка матчится только владельцем
        if ($tender->owner_company_id !== null && (int) $tender->owner_company_id !== (int) $profile->company_id) {
            return false;
        }

        // law_type: пустой список = любой закон; other проходит только при пустом
        $lawTypes = $profile->law_types ?? [];
        if ($lawTypes !== []) {
            if ($tender->law_type === LawType::Other) {
                return false;
            }
            if (! in_array($tender->law_type->value, $lawTypes, true)) {
                return false;
            }
        }

        // Регион ∉ excluded_regions
        $excluded = $profile->excluded_regions ?? [];
        if ($tender->region !== null && in_array($tender->region, $excluded, true)) {
            return false;
        }

        // Цена: фильтр только когда заданы ОБЕ границы и price NOT NULL
        $price = $tender->price;
        $min = $profile->min_price;
        $max = $profile->max_price;
        if ($price !== null && $min !== null && $max !== null) {
            if (bccomp($price, $min, 2) < 0 || bccomp($price, $max, 2) > 0) {
                return false;
            }
        }

        return true;
    }
}
