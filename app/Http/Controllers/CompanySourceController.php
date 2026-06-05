<?php

namespace App\Http\Controllers;

use App\Models\CompanySource;
use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CompanySourceController extends Controller
{
    public function index(): Response
    {
        $companySources = CompanySource::query()->get()->keyBy('source_id');

        return Inertia::render('Sources/Index', [
            'sources' => Source::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Source $source) => [
                    'id' => $source->id,
                    'code' => $source->code,
                    'name' => $source->name,
                    'connection_type' => $source->connection_type,
                    'enabled' => (bool) ($companySources[$source->id]->is_active ?? false),
                ])
                ->values(),
        ]);
    }

    public function update(Request $request, Source $source): RedirectResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $companyId = $request->user()->company_id;

        if ($validated['enabled']) {
            $plan = $request->user()->company->activeSubscription()?->plan;
            $activeCount = CompanySource::query()->where('is_active', true)->count();
            $alreadyActive = (bool) CompanySource::query()->where('source_id', $source->id)->value('is_active');

            if ($plan === null || (! $alreadyActive && $activeCount >= $plan->max_sources)) {
                throw ValidationException::withMessages([
                    'enabled' => $plan === null
                        ? 'У компании нет активной подписки.'
                        : "Достигнут лимит тарифа: {$plan->max_sources} источников.",
                ]);
            }
        }

        CompanySource::query()->updateOrCreate(
            ['company_id' => $companyId, 'source_id' => $source->id],
            ['is_active' => $validated['enabled']],
        );

        return back()->with('success', $validated['enabled'] ? 'Источник включён.' : 'Источник выключен.');
    }
}
