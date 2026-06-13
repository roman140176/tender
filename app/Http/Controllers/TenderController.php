<?php

namespace App\Http\Controllers;

use App\Enums\LawType;
use App\Enums\RelevanceLevel;
use App\Enums\WorkflowStatus;
use App\Http\Requests\TenderStatusRequest;
use App\Models\CompanyTender;
use App\Models\Source;
use App\Models\TenderComment;
use App\Models\TenderMatch;
use App\Models\TenderStatusHistory;
use App\Scoring\MatchReasonPresenter;
use App\Tenders\TenderListQuery;
use Illuminate\Http\RedirectResponse;
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

    public function show(Request $request, CompanyTender $companyTender): Response
    {
        $companyTender->load('tender.source', 'assignee:id,name');

        $matches = TenderMatch::query()
            ->where('tender_id', $companyTender->tender_id)
            ->with('searchProfile:id,name')
            ->get();

        /** @var TenderMatch|null $best */
        $best = $matches->sortByDesc('score')->first();

        $comments = TenderComment::query()
            ->where('tender_id', $companyTender->tender_id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get(['id', 'user_id', 'comment', 'created_at']);

        $history = TenderStatusHistory::query()
            ->where('tender_id', $companyTender->tender_id)
            ->orderByDesc('created_at')
            ->get(['id', 'old_status', 'new_status', 'created_at']);

        $canManage = (bool) $request->user()?->can('manage-tender-workflow');

        return Inertia::render('Tenders/Show', [
            'companyTender' => $companyTender,
            'tender' => $companyTender->tender,
            'lawLabel' => $companyTender->tender->law_type->label(),
            'match' => $best === null ? null : [
                'score' => $best->score,
                'level' => $best->level->value,
                'levelLabel' => $best->level->label(),
                'recommendation' => $best->action_recommendation->label(),
                'reasonLines' => MatchReasonPresenter::lines($best->reason ?? []),
                'risks' => MatchReasonPresenter::risks($best->risk_flags ?? []),
                'matchedKeywords' => $best->matched_keywords ?? [],
                'matchedNegativeKeywords' => $best->matched_negative_keywords ?? [],
                'profiles' => $matches
                    ->map(fn (TenderMatch $m): array => ['name' => $m->searchProfile?->name, 'score' => $m->score])
                    ->values(),
            ],
            'comments' => $comments,
            'history' => $history,
            'statusOptions' => WorkflowStatus::options(),
            'assignableUsers' => $canManage
                ? $companyTender->company->users()->where('is_active', true)->get(['id', 'name'])
                : [],
            'canManage' => $canManage,
        ]);
    }

    public function updateStatus(TenderStatusRequest $request, CompanyTender $companyTender): RedirectResponse
    {
        $new = WorkflowStatus::from($request->validated()['status']);
        $oldRaw = $companyTender->getRawOriginal('status');
        $old = WorkflowStatus::from($oldRaw);

        if ($new !== $old) {
            $companyTender->update(['status' => $new]);
            TenderStatusHistory::create([
                'company_id' => $companyTender->company_id,
                'tender_id' => $companyTender->tender_id,
                'user_id' => $request->user()->id,
                'old_status' => $old->value,
                'new_status' => $new->value,
            ]);
        }

        return back()->with('success', 'Статус обновлён.');
    }
}
