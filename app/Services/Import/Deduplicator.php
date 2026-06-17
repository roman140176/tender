<?php

namespace App\Services\Import;

use App\Enums\SourceConnectionType;
use App\Models\Source;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Deduplicator
{
    /** Содержательные поля: их изменения трекаются в tender_changes (спека §4.2). */
    private const TRACKED_FIELDS = [
        'title', 'description', 'price', 'deadline_at', 'source_status',
        'customer_name', 'customer_inn', 'region',
    ];

    /** Кэш результатов isManualSource() во избежание N+1 при пакетной обработке.
     *
     * @var array<int, bool>
     */
    private array $manualSourceCache = [];

    /**
     * Find-or-create по каскаду §6.5. Для manual owner обязателен (Р13).
     */
    public function process(NormalizedTender $n, int $sourceId, ?int $ownerCompanyId, ?int $collectRunId = null): DedupResult
    {
        if ($ownerCompanyId === null && $this->isManualSource($sourceId)) {
            throw new InvalidArgumentException('manual-источник требует owner_company_id (Р13).');
        }

        $externalId = $n->number !== null && $n->number !== ''
            ? $n->number
            : $this->syntheticExternalId($n, $ownerCompanyId);

        try {
            return DB::transaction(function () use ($n, $sourceId, $ownerCompanyId, $externalId, $collectRunId) {
                // Сериализация конкурентных воркеров по идентичности контента (ревью A1).
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$n->contentHash]);

                $existing = $this->findExisting($n, $sourceId, $ownerCompanyId, $externalId);

                if ($existing === null) {
                    return new DedupResult($this->create($n, $sourceId, $ownerCompanyId, $externalId), 'created');
                }

                return $this->refresh($existing, $n, $collectRunId);
            });
        } catch (UniqueConstraintViolationException) {
            // Гонка двух чанков по одному (source_id, external_id): конкурент вставил строку
            // между нашим findExisting (вернул null) и INSERT. Один повтор — теперь строка есть.
            return DB::transaction(function () use ($n, $sourceId, $ownerCompanyId, $externalId, $collectRunId) {
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$n->contentHash]);

                $existing = $this->findExisting($n, $sourceId, $ownerCompanyId, $externalId);

                if ($existing === null) {
                    // Строка всё ещё не найдена после нарушения уникальности — подлинная ошибка.
                    throw new UniqueConstraintViolationException(
                        'pgsql',
                        'retry failed: row still not found after UniqueConstraintViolationException',
                        [],
                        new \PDOException('SQLSTATE[23505]'),
                    );
                }

                return $this->refresh($existing, $n, $collectRunId);
            });
        }
    }

    private function isManualSource(int $sourceId): bool
    {
        return $this->manualSourceCache[$sourceId] ??= (function () use ($sourceId): bool {
            $connectionType = Source::query()->whereKey($sourceId)->value('connection_type');

            // value() через Eloquent Builder возвращает cast-значение (enum), сравниваем по строковому значению
            if ($connectionType instanceof \BackedEnum) {
                return $connectionType->value === SourceConnectionType::Manual->value;
            }

            return $connectionType === SourceConnectionType::Manual->value;
        })();
    }

    protected function findExisting(NormalizedTender $n, int $sourceId, ?int $ownerCompanyId, string $externalId): ?Tender
    {
        /** @param Builder<Tender> $q */
        $scope = fn (Builder $q): Builder => $q
            ->where('source_id', $sourceId)
            ->when(
                $ownerCompanyId !== null,
                fn (Builder $q) => $q->where('owner_company_id', $ownerCompanyId),
                fn (Builder $q) => $q->whereNull('owner_company_id'),
            );

        // 1) source_id + external_id
        /** @var Tender|null $found */
        $found = $scope(Tender::query())->where('external_id', $externalId)->first();
        if ($found) {
            return $found;
        }

        // 2) source_url (только непустой)
        if ($n->sourceUrl !== '') {
            /** @var Tender|null $found */
            $found = $scope(Tender::query())->where('source_url', $n->sourceUrl)->first();
            if ($found) {
                return $found;
            }
        }

        /** @var Tender|null */
        return $scope(Tender::query())->where('content_hash', $n->contentHash)->first();
    }

    protected function create(NormalizedTender $n, int $sourceId, ?int $ownerCompanyId, string $externalId): Tender
    {
        return Tender::create([
            'source_id' => $sourceId,
            'owner_company_id' => $ownerCompanyId,
            'external_id' => $externalId,
            'number' => $n->number,
            'source_url' => $n->sourceUrl,
            'title' => $n->title,
            'description' => $n->description,
            'customer_name' => $n->customerName,
            'customer_inn' => $n->customerInn,
            'region' => $n->region,
            'price' => $n->price,
            'currency' => $n->currency,
            'law_type' => $n->lawType,
            'procedure_type' => $n->procedureType,
            'source_status' => $n->sourceStatus,
            'published_at' => $n->publishedAt,
            'deadline_at' => $n->deadlineAt,
            'okpd2' => $n->okpd2,
            'content_hash' => $n->contentHash,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function refresh(Tender $tender, NormalizedTender $n, ?int $collectRunId): DedupResult
    {
        $incoming = [
            'title' => $n->title,
            'description' => $n->description,
            'price' => $n->price,
            'deadline_at' => $n->deadlineAt,
            'source_status' => $n->sourceStatus,
            'customer_name' => $n->customerName,
            'customer_inn' => $n->customerInn,
            'region' => $n->region,
        ];

        $changed = false;
        foreach (self::TRACKED_FIELDS as $field) {
            $new = $incoming[$field];
            $old = $tender->getAttribute($field);

            $oldCmp = $this->normalizeCmp($old);
            $newCmp = $this->normalizeCmp($new);

            // null из источника НЕ затирает существующее; равные пропускаем
            if ($newCmp === null || (string) $oldCmp === (string) $newCmp) {
                continue;
            }

            $tender->changeLogs()->create([
                'source_collect_run_id' => $collectRunId,
                'field' => $field,
                'old_value' => $oldCmp !== null ? (string) $oldCmp : null,
                'new_value' => (string) $newCmp,
            ]);
            $tender->setAttribute($field, $new);
            $changed = true;
        }

        $tender->last_seen_at = now();
        $tender->save();

        return new DedupResult($tender, $changed ? 'updated' : 'unchanged');
    }

    /**
     * Нормализует значение поля для сравнения:
     * enum → строковое значение, DateTimeInterface → 'Y-m-d H:i:s', остальное → как есть.
     */
    private function normalizeCmp(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    /** Синтетический external_id для строк без номера (спека §6.3). */
    private function syntheticExternalId(NormalizedTender $n, ?int $ownerCompanyId): string
    {
        return hash('sha256', implode('|', [
            (string) $ownerCompanyId,
            Canonicalizer::text($n->title),
            Canonicalizer::text($n->customerName),
            $n->publishedAt?->format('Y-m-d') ?? '',
            $n->price ?? '',
        ]));
    }
}
