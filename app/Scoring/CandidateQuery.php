<?php

namespace App\Scoring;

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Services\Import\Canonicalizer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CandidateQuery
{
    /**
     * Предфильтр кандидатов §7.6: FTS-ветка (не-exact ключи через search_vector) OR
     * trigram/ILIKE-ветка по title (exact-фразы и аббревиатуры ≤4 симв.), плюс
     * owner-scope (глобальные + свои manual) и включённые источники.
     *
     * @param  Collection<int, Keyword>  $keywords
     * @param  list<int>  $enabledSourceIds
     * @return Builder<Tender>
     */
    public function build(
        SearchProfile $profile,
        Collection $keywords,
        array $enabledSourceIds,
        ?CarbonInterface $sinceUpdatedAt = null,
    ): Builder {
        /** @var list<string> $ftsValues */
        $ftsValues = [];
        /** @var list<string> $ilikeValues */
        $ilikeValues = [];

        foreach ($keywords as $kw) {
            if ($kw->type === KeywordType::Negative) {
                continue;
            }
            if ($kw->type === KeywordType::Exact) {
                $ilikeValues[] = $kw->value;

                continue;
            }
            $ftsValues[] = $kw->value;
            if (mb_strlen(Canonicalizer::text($kw->value)) <= 4) {
                $ilikeValues[] = $kw->value; // короткие аббревиатуры — слепая зона FTS
            }
        }

        $companyId = (int) $profile->company_id;
        $deadlineMinDays = (int) ($profile->deadline_min_days ?? 0);

        $query = Tender::query()
            ->where(function (Builder $q) use ($companyId) {
                $q->whereNull('owner_company_id')->orWhere('owner_company_id', $companyId);
            })
            ->whereIn('source_id', $enabledSourceIds)
            // §4.2 шаг 2: кандидатный фильтр дедлайна (НЕ §7.1). Дефолт 0 → отсекаются просроченные.
            ->where(function (Builder $q) use ($deadlineMinDays) {
                $q->whereNull('deadline_at')
                    ->orWhereRaw('deadline_at >= now() + make_interval(days => ?)', [$deadlineMinDays]);
            })
            ->when($sinceUpdatedAt !== null, fn (Builder $q) => $q->where('updated_at', '>=', $sinceUpdatedAt));

        $query->where(function (Builder $q) use ($ftsValues, $ilikeValues) {
            if ($ftsValues === [] && $ilikeValues === []) {
                $q->whereRaw('1 = 0'); // профиль без положительных ключей — кандидатов нет

                return;
            }
            foreach ($ftsValues as $value) {
                $q->orWhereRaw("search_vector @@ plainto_tsquery('russian', ?)", [$value]);
            }
            foreach ($ilikeValues as $value) {
                // Нормализуем title и значение как Canonicalizer (lower + ё→е), чтобы предфильтр
                // не терял кандидатов, которые KeywordMatchResolver затем подтвердит.
                $q->orWhereRaw("replace(lower(title), 'ё', 'е') LIKE ?", ['%'.$this->normalizedLike($value).'%']);
            }
        });

        return $query;
    }

    /** lower + ё→е (как Canonicalizer) + экранирование спецсимволов LIKE. */
    private function normalizedLike(string $value): string
    {
        $normalized = str_replace('ё', 'е', mb_strtolower($value));

        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalized);
    }
}
