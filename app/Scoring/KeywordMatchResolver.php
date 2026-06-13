<?php

namespace App\Scoring;

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\Tender;
use App\Services\Import\Canonicalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class KeywordMatchResolver
{
    /**
     * Определяет поле попадания каждого ключа в закупке:
     *  - exact: смежный нормализованный substring (title приоритетнее description);
     *  - positive не-exact: морфология через plainto_tsquery('russian') ИЛИ substring (для аббревиатур);
     *  - negative: только морфология (substring дал бы ложные исключения по подстроке внутри слов).
     *
     * @param  Collection<int, Keyword>  $keywords
     * @return array{positive: list<KeywordHit>, negative: list<KeywordHit>}
     */
    public function resolve(Tender $tender, Collection $keywords): array
    {
        $titleCanon = Canonicalizer::text($tender->title);
        $descCanon = Canonicalizer::text($tender->description);

        /** @var list<string> $ftsValues */
        $ftsValues = $keywords
            ->reject(fn (Keyword $k) => $k->type === KeywordType::Exact)
            ->map(fn (Keyword $k) => $k->value)
            ->values()
            ->all();

        $ftsMap = $this->ftsHits($tender, $ftsValues);

        $positive = [];
        $negative = [];

        foreach ($keywords as $kw) {
            $field = $this->fieldFor($kw, $titleCanon, $descCanon, $ftsMap);
            $hit = new KeywordHit($kw->value, $kw->type, $field);
            if ($kw->type === KeywordType::Negative) {
                $negative[] = $hit;
            } else {
                $positive[] = $hit;
            }
        }

        return ['positive' => $positive, 'negative' => $negative];
    }

    /**
     * @param  array<string, array{title: bool, desc: bool}>  $ftsMap
     * @return 'title'|'description'|null
     */
    private function fieldFor(Keyword $kw, string $titleCanon, string $descCanon, array $ftsMap): ?string
    {
        $valueCanon = Canonicalizer::text($kw->value);
        $subTitle = $valueCanon !== '' && str_contains($titleCanon, $valueCanon);
        $subDesc = $valueCanon !== '' && str_contains($descCanon, $valueCanon);

        if ($kw->type === KeywordType::Exact) {
            return $subTitle ? 'title' : ($subDesc ? 'description' : null);
        }

        if ($kw->type === KeywordType::Negative) {
            // Только FTS: substring дал бы ложные исключения по подстроке внутри слов
            // (короткий негатив «бу» матчит «требуются», «обувь» через str_contains).
            // Стеммер 'russian' уже ловит настоящие негативы, включая короткие леммы.
            $fts = $ftsMap[$kw->value] ?? ['title' => false, 'desc' => false];

            return $fts['title'] ? 'title' : ($fts['desc'] ? 'description' : null);
        }

        $fts = $ftsMap[$kw->value] ?? ['title' => false, 'desc' => false];
        $titleHit = $subTitle || $fts['title'];
        $descHit = $subDesc || $fts['desc'];

        return $titleHit ? 'title' : ($descHit ? 'description' : null);
    }

    /**
     * Морфологическое попадание каждого значения в title/description через стеммер.
     * Один запрос на закупку: tsvector названия/описания считается в CTE один раз.
     *
     * @param  list<string>  $values
     * @return array<string, array{title: bool, desc: bool}>
     */
    private function ftsHits(Tender $tender, array $values): array
    {
        $values = array_values(array_unique($values));
        if ($values === []) {
            return [];
        }

        $columns = [];
        $bindings = [$tender->title ?? '', $tender->description ?? ''];
        foreach ($values as $i => $value) {
            $columns[] = "(v.tv_title @@ plainto_tsquery('russian', ?))::int AS t{$i}";
            $columns[] = "(v.tv_desc @@ plainto_tsquery('russian', ?))::int AS d{$i}";
            $bindings[] = $value;
            $bindings[] = $value;
        }

        $sql = "WITH v AS (SELECT to_tsvector('russian', coalesce(?, '')) AS tv_title, "
            ."to_tsvector('russian', coalesce(?, '')) AS tv_desc) SELECT "
            .implode(', ', $columns).' FROM v';

        $row = DB::selectOne($sql, $bindings);

        $map = [];
        foreach ($values as $i => $value) {
            $map[$value] = [
                'title' => (bool) ($row->{"t{$i}"} ?? 0),
                'desc' => (bool) ($row->{"d{$i}"} ?? 0),
            ];
        }

        return $map;
    }
}
