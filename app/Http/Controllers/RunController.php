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
        $companyId = $request->user()?->company_id;

        return Inertia::render('Runs/Index', [
            // RadarRun авто-скоупится (BelongsToCompany); ограничиваем последними 50.
            'radarRuns' => RadarRun::query()
                ->with('searchProfile:id,name')
                ->latest('started_at')
                ->limit(50)
                ->get(),
            // SourceCollectRun БЕЗ scope — фильтруем company_id явно.
            'collectRuns' => SourceCollectRun::query()
                ->where('company_id', $companyId)
                ->with('source:id,name')
                ->latest('started_at')
                ->limit(50)
                ->get(),
        ]);
    }
}
