<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RunTrigger;
use App\Http\Controllers\Controller;
use App\Jobs\CollectSourceJob;
use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SourceCollectController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Sources/Index', [
            'sources' => Source::orderBy('name')->get(['id', 'name', 'code', 'connection_type', 'adapter_class', 'schedule_cron', 'last_collected_at']),
        ]);
    }

    public function trigger(Request $request, Source $source): RedirectResponse
    {
        $request->user()->can('superadmin') || abort(403);

        CollectSourceJob::dispatch($source->id, RunTrigger::Manual->value);

        return redirect()->back()->with('success', "Сбор источника «{$source->name}» поставлен в очередь.");
    }
}
