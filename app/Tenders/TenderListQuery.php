<?php

namespace App\Tenders;

use App\Models\CompanyTender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class TenderListQuery
{
    /**
     * Список закупок компании (per-company проекция) с фильтрами 13.2 ТЗ.
     * Полагается на активный TenantContext (HTTP) — company_tenders скоупится по компании.
     * Скрывает осиротевшие проекции без единого tender_match (follow-up A4 #2).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, CompanyTender>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = CompanyTender::query()
            ->with(['tender.source', 'assignee:id,name'])
            ->whereHas('matches') // только реально сматченные (не осиротевшие)
            ->when($this->filled($filters, 'status'), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($this->filled($filters, 'level'), fn (Builder $q) => $q->where('best_level', $filters['level']))
            ->when($this->filled($filters, 'assignee'), fn (Builder $q) => $q->where('assigned_user_id', (int) $filters['assignee']))
            ->when($this->filled($filters, 'profile_id'), fn (Builder $q) => $q->whereHas(
                'matches',
                fn (Builder $m) => $m->where('search_profile_id', (int) $filters['profile_id']),
            ))
            ->whereHas('tender', function (Builder $t) use ($filters): void {
                $t->when($this->filled($filters, 'source_id'), fn (Builder $q) => $q->where('source_id', (int) $filters['source_id']))
                    ->when($this->filled($filters, 'law_type'), fn (Builder $q) => $q->where('law_type', $filters['law_type']))
                    ->when($this->filled($filters, 'region'), fn (Builder $q) => $q->where('region', $filters['region']))
                    ->when($this->filled($filters, 'customer'), fn (Builder $q) => $q->where('customer_name', 'ILIKE', '%'.$this->escape((string) $filters['customer']).'%'))
                    ->when($this->filled($filters, 'price_min'), fn (Builder $q) => $q->whereNotNull('price')->where('price', '>=', $filters['price_min']))
                    ->when($this->filled($filters, 'price_max'), fn (Builder $q) => $q->whereNotNull('price')->where('price', '<=', $filters['price_max']))
                    ->when($this->filled($filters, 'published_from'), fn (Builder $q) => $q->where('published_at', '>=', $filters['published_from']))
                    ->when($this->filled($filters, 'deadline_to'), fn (Builder $q) => $q->where('deadline_at', '<=', $filters['deadline_to']))
                    ->when($this->filled($filters, 'q'), function (Builder $q) use ($filters): void {
                        $like = '%'.$this->escape((string) $filters['q']).'%';
                        $q->where(fn (Builder $w) => $w->where('title', 'ILIKE', $like)->orWhere('description', 'ILIKE', $like));
                    });
            })
            ->orderByDesc('best_score')
            ->orderByDesc('first_matched_at');

        return $query->paginate($perPage)->withQueryString();
    }

    /** @param array<string, mixed> $filters */
    private function filled(array $filters, string $key): bool
    {
        return isset($filters[$key]) && $filters[$key] !== '';
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
