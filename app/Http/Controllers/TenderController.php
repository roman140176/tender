<?php

namespace App\Http\Controllers;

use App\Enums\LawType;
use App\Enums\RelevanceLevel;
use App\Enums\WorkflowStatus;
use App\Models\Source;
use App\Tenders\TenderListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TenderController extends Controller
{
    public function index(Request $request, TenderListQuery $list): Response
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_keys(WorkflowStatus::options()))],
            'level' => ['nullable', Rule::in(array_map(fn (RelevanceLevel $l): string => $l->value, RelevanceLevel::cases()))],
            'law_type' => ['nullable', Rule::in(array_map(fn (LawType $l): string => $l->value, LawType::cases()))],
            'assignee' => ['nullable', 'integer'],
            'profile_id' => ['nullable', 'integer'],
            'source_id' => ['nullable', 'integer'],
            'region' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'price_min' => ['nullable', 'numeric'],
            'price_max' => ['nullable', 'numeric'],
            'published_from' => ['nullable', 'date'],
            'deadline_to' => ['nullable', 'date'],
        ]);

        $filters = $request->only([
            'status', 'level', 'assignee', 'profile_id', 'source_id',
            'law_type', 'region', 'customer', 'price_min', 'price_max',
            'published_from', 'deadline_to', 'q',
        ]);

        return Inertia::render('Tenders/Index', [
            'tenders' => $list->paginate($filters),
            'filters' => (object) $filters,
            'filterOptions' => [
                'statuses' => WorkflowStatus::options(),
                'levels' => collect(RelevanceLevel::cases())->mapWithKeys(fn (RelevanceLevel $l): array => [$l->value => $l->label()]),
                'laws' => collect(LawType::cases())->mapWithKeys(fn (LawType $l): array => [$l->value => $l->label()]),
                'sources' => Source::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }
}
