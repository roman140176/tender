<?php

namespace App\Http\Controllers;

use App\Enums\RelevanceLevel;
use App\Models\CompanyTender;
use App\Models\RadarRun;
use App\Models\SourceCollectRun;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Окно (дней) для счётчиков и графика динамики (дизайн A7 §2.8 п.1/п.5). */
    private const WINDOW_DAYS = 30;

    private const HIGH_PRIORITY_LIMIT = 10;

    private const DEADLINES_LIMIT = 10;

    private const RUNS_LIMIT = 10;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $companyId = $user?->company_id;

        // Суперадмин не привязан к компании: company-scoped дашборд пуст
        // (его данные — на /runs eisRuns). Без guard'а company_id=null отключил бы
        // tenant-scope и показал бы данные ВСЕХ компаний. Динамику строим нулевым
        // рядом (emptyDynamics), а не запросом — иначе агрегат без scope посчитал бы все компании.
        if ($companyId === null) {
            return Inertia::render('Dashboard', [
                'counters' => ['new' => 0, 'relevant' => 0],
                'highPriority' => [],
                'upcomingDeadlines' => [],
                'recentRuns' => ['radar' => [], 'collect' => []],
                'dynamics' => $this->emptyDynamics(),
            ]);
        }

        // Начало окна (включительно) — N-1 дней назад, начало дня (UTC).
        $windowStart = Carbon::now()->subDays(self::WINDOW_DAYS - 1)->startOfDay();

        // Колонки tender для блоков (через relation tender — Tender глобальная, scope не нужен).
        $tenderColumns = ['id', 'title', 'region', 'price', 'deadline_at'];

        // 1. Счётчики (company_tenders авто-scoped по first_matched_at за окно).
        $windowQuery = CompanyTender::query()
            ->where('first_matched_at', '>=', $windowStart);

        $counters = [
            'new' => (clone $windowQuery)->count(),
            'relevant' => (clone $windowQuery)
                ->whereIn('best_level', [RelevanceLevel::High->value, RelevanceLevel::Medium->value])
                ->count(),
        ];

        // 2. Высокоприоритетные (best_level=high), топ по best_score.
        $highPriority = CompanyTender::query()
            ->select(['id', 'company_id', 'tender_id', 'best_score', 'best_level'])
            ->where('best_level', RelevanceLevel::High->value)
            ->with(['tender' => fn ($q) => $q->select($tenderColumns)])
            ->orderByDesc('best_score')
            ->limit(self::HIGH_PRIORITY_LIMIT)
            ->get();

        // 3. Ближайшие дедлайны: tender.deadline_at в будущем, ASC. whereHas по relation tender.
        $now = Carbon::now();
        $upcomingDeadlines = CompanyTender::query()
            ->select(['id', 'company_id', 'tender_id'])
            ->whereHas('tender', fn ($q) => $q->where('deadline_at', '>=', $now))
            ->with(['tender' => fn ($q) => $q->select($tenderColumns)])
            ->orderBy(
                CompanyTender::query()
                    ->getModel()
                    ->tender()
                    ->getRelated()
                    ->newQuery()
                    ->select('deadline_at')
                    ->whereColumn('tenders.id', 'company_tenders.tender_id')
            )
            ->limit(self::DEADLINES_LIMIT)
            ->get();

        // 4. Последние запуски: radar (авто-scoped) + collect (явный company_id).
        $recentRuns = [
            'radar' => RadarRun::query()
                ->select(['id', 'company_id', 'search_profile_id', 'trigger', 'status', 'started_at', 'finished_at', 'processed_count', 'matched_count', 'notified_count', 'error_count'])
                ->with('searchProfile:id,name')
                ->latest('started_at')
                ->limit(self::RUNS_LIMIT)
                ->get(),
            'collect' => SourceCollectRun::query()
                ->select(['id', 'source_id', 'company_id', 'trigger', 'status', 'started_at', 'finished_at', 'fetched_count', 'new_count', 'error_count'])
                ->where('company_id', $companyId)
                ->with('source:id,name')
                ->latest('started_at')
                ->limit(self::RUNS_LIMIT)
                ->get(),
        ];

        // 5. Динамика: агрегат по date(first_matched_at) за окно, дозаполнен нулями.
        $dynamics = $this->dynamics($windowStart);

        return Inertia::render('Dashboard', [
            'counters' => $counters,
            'highPriority' => $highPriority,
            'upcomingDeadlines' => $upcomingDeadlines,
            'recentRuns' => $recentRuns,
            'dynamics' => $dynamics,
        ]);
    }

    /**
     * Агрегат company_tenders по дню first_matched_at за окно, с нулевыми днями.
     *
     * @return list<array{date: string, count: int}>
     */
    private function dynamics(Carbon $windowStart): array
    {
        // PostgreSQL: группировка по дате с приведением к ::date.
        $rows = CompanyTender::query()
            ->where('first_matched_at', '>=', $windowStart)
            ->selectRaw('first_matched_at::date as d, count(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd'); // ['2026-06-10' => 3, ...]

        $series = [];
        $cursor = $windowStart->copy();
        for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
            $key = $cursor->format('Y-m-d');
            $series[] = [
                'date' => $key,
                'count' => (int) ($rows[$key] ?? 0),
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * Нулевой ряд динамики на окно (для суперадмина — без обращения к БД).
     *
     * @return list<array{date: string, count: int}>
     */
    private function emptyDynamics(): array
    {
        $series = [];
        $cursor = Carbon::now()->subDays(self::WINDOW_DAYS - 1)->startOfDay();
        for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
            $series[] = ['date' => $cursor->format('Y-m-d'), 'count' => 0];
            $cursor->addDay();
        }

        return $series;
    }
}
