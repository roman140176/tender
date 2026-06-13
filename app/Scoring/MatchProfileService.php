<?php

namespace App\Scoring;

use App\Enums\RelevanceLevel;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\CompanySource;
use App\Models\CompanyTender;
use App\Models\Keyword;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\TenderMatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class MatchProfileService
{
    public function __construct(
        private readonly CandidateQuery $candidates,
        private readonly KeywordMatchResolver $resolver,
        private readonly Scorer $scorer,
        private readonly HardFilter $hardFilter,
    ) {}

    /**
     * Прогоняет профиль по кандидатам, скорит и персистит результат в рамках radar_run.
     * Не полагается на TenantContext — все запросы используют явный company_id.
     */
    public function run(SearchProfile $profile, RunTrigger $trigger, ?CarbonInterface $sinceUpdatedAt = null): RadarRun
    {
        $companyId = (int) $profile->company_id;
        $enabledSourceIds = $this->enabledSourceIds($companyId);

        $run = RadarRun::create([
            'company_id' => $companyId,
            'search_profile_id' => $profile->id,
            'trigger' => $trigger,
            'status' => RunStatus::Running,
            'started_at' => now(),
        ]);

        /** @var Collection<int, Keyword> $keywords */
        $keywords = $profile->keywords; // relation-свойство: переиспользует with('keywords') из джоба (без лишнего SELECT); при прямом вызове из теста лениво загрузит один раз
        $weights = ScoringWeights::for($profile);

        $processed = 0;
        $matched = 0;
        $errors = 0;
        $errorLines = [];

        $this->candidates->build($profile, $keywords, $enabledSourceIds, $sinceUpdatedAt)
            ->chunkById(500, function (Collection $tenders) use (
                &$processed, &$matched, &$errors, &$errorLines, $profile, $keywords, $weights, $companyId, $enabledSourceIds, $run
            ): void {
                foreach ($tenders as $tender) {
                    $processed++;
                    try {
                        // $enabledSourceIds вычислен один раз до цикла — не пересчитываем в чанке (N+1).
                        if (! $this->hardFilter->passes($tender, $profile, $enabledSourceIds)) {
                            continue;
                        }

                        ['positive' => $positive, 'negative' => $negative] = $this->resolver->resolve($tender, $keywords);

                        // "положительное" здесь = любой сработавший НЕ-негативный ключ
                        // (main/exact/broad/high/low), НЕ KeywordType::isPositive(). См. решение №8.
                        $hasPositive = false;
                        foreach ($positive as $hit) {
                            if ($hit->matched()) {
                                $hasPositive = true;
                                break;
                            }
                        }
                        if (! $hasPositive) {
                            continue;
                        }

                        $result = $this->scorer->score($tender, $profile, $weights, $positive, $negative);

                        if ($result->excluded) {
                            $this->removeMatch($companyId, (int) $tender->id, (int) $profile->id);

                            continue;
                        }

                        $this->persist($companyId, (int) $tender->id, (int) $profile->id, (int) $run->id, $result);
                        $matched++;
                    } catch (Throwable $e) {
                        $errors++;
                        $errorLines[] = "tender {$tender->id}: ".mb_substr($e->getMessage(), 0, 200);
                    }
                }
            });

        $run->update([
            'status' => RunStatus::Ok,
            'finished_at' => now(),
            'processed_count' => $processed,
            'matched_count' => $matched,
            'error_count' => $errors,
            'log' => $errorLines !== [] ? implode("\n", array_slice($errorLines, 0, 100)) : null,
        ]);

        return $run->refresh();
    }

    /** @return list<int> */
    private function enabledSourceIds(int $companyId): array
    {
        $ids = CompanySource::withoutTenancy()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->pluck('source_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // manual всегда доступен: компания импортирует собственные данные.
        $manualId = Source::query()->where('code', 'manual')->value('id');
        if ($manualId !== null && ! in_array((int) $manualId, $ids, true)) {
            $ids[] = (int) $manualId;
        }

        return $ids;
    }

    private function persist(int $companyId, int $tenderId, int $profileId, int $runId, ScoreResult $result): void
    {
        DB::transaction(function () use ($companyId, $tenderId, $profileId, $runId, $result): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['ct:'.$companyId.':'.$tenderId]);

            TenderMatch::withoutTenancy()->updateOrCreate(
                ['search_profile_id' => $profileId, 'tender_id' => $tenderId],
                [
                    'company_id' => $companyId,
                    'radar_run_id' => $runId,
                    'score' => $result->score,
                    'level' => $result->level,
                    'reason' => $result->reason,
                    'matched_keywords' => $result->matchedKeywords,
                    'matched_negative_keywords' => $result->matchedNegativeKeywords,
                    'risk_flags' => $result->riskFlags,
                    'action_recommendation' => $result->recommendation,
                ],
            );

            $this->recomputeBest($companyId, $tenderId, createIfMissing: true);
        });
    }

    private function removeMatch(int $companyId, int $tenderId, int $profileId): void
    {
        DB::transaction(function () use ($companyId, $tenderId, $profileId): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['ct:'.$companyId.':'.$tenderId]);

            TenderMatch::withoutTenancy()
                ->where('search_profile_id', $profileId)
                ->where('tender_id', $tenderId)
                ->delete();

            $this->recomputeBest($companyId, $tenderId, createIfMissing: false);
        });
    }

    /**
     * Пересчёт best_* по MAX(score) всех матчей пары (company, tender). Явный company_id,
     * без TenantContext/CompanyTender::matches(). first_matched_at ставится только при создании.
     */
    private function recomputeBest(int $companyId, int $tenderId, bool $createIfMissing): void
    {
        $best = (int) TenderMatch::withoutTenancy()
            ->where('company_id', $companyId)
            ->where('tender_id', $tenderId)
            ->max('score');

        $level = RelevanceLevel::fromScore($best);

        $existing = CompanyTender::withoutTenancy()
            ->where('company_id', $companyId)
            ->where('tender_id', $tenderId)
            ->first();

        if ($existing !== null) {
            $existing->update(['best_score' => $best, 'best_level' => $level]);

            return;
        }

        if ($createIfMissing) {
            CompanyTender::create([
                'company_id' => $companyId,
                'tender_id' => $tenderId,
                'best_score' => $best,
                'best_level' => $level,
                'first_matched_at' => now(),
            ]);
        }
    }
}
