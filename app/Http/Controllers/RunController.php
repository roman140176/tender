<?php

namespace App\Http\Controllers;

use App\Models\RadarRun;
use App\Models\SourceCollectRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RunController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $companyId = $user?->company_id;
        $isSuperadmin = $user?->can('superadmin') ?? false;

        return Inertia::render('Runs/Index', [
            // RadarRun авто-скоупится (BelongsToCompany); ограничиваем последними 50.
            'radarRuns' => $isSuperadmin
                ? []
                : RadarRun::query()
                    ->select(['id', 'company_id', 'search_profile_id', 'trigger', 'status', 'started_at', 'finished_at', 'processed_count', 'matched_count', 'error_count'])
                    ->with('searchProfile:id,name')
                    ->latest('started_at')
                    ->limit(50)
                    ->get(),

            // SourceCollectRun БЕЗ scope — фильтруем company_id явно.
            // Для суперадмина — company-scoped пусто (у него нет компании).
            'collectRuns' => SourceCollectRun::query()
                ->select(['id', 'source_id', 'company_id', 'trigger', 'status', 'started_at', 'finished_at', 'fetched_count', 'new_count', 'error_count'])
                ->where('company_id', $companyId)
                ->with('source:id,name')
                ->latest('started_at')
                ->limit(50)
                ->get(),

            // ЕИС-раны (company_id IS NULL) — только для суперадмина.
            'eisRuns' => $isSuperadmin
                ? SourceCollectRun::query()
                    ->select(['id', 'source_id', 'company_id', 'trigger', 'status', 'started_at', 'finished_at', 'fetched_count', 'new_count', 'updated_count', 'error_count'])
                    ->whereNull('company_id')
                    ->with('source:id,name')
                    ->latest('started_at')
                    ->limit(100)
                    ->get()
                : collect(),
        ]);
    }
}
