<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Функциональный GIN trigram под нормализованную ILIKE-ветку CandidateQuery.
        // Подтверждено EXPLAIN (A4): без него replace(lower(title),'ё','е') LIKE идёт Seq Scan.
        DB::statement("CREATE INDEX IF NOT EXISTS tenders_title_norm_trgm ON tenders USING GIN (replace(lower(title), 'ё', 'е') gin_trgm_ops)");
        // Композит owner/source НЕ добавлен: GIN(search_vector) покрывает горячий путь,
        // owner_company_id/source_id уже btree/FK-индексы (A1). См. EXPLAIN в коммите.
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tenders_title_norm_trgm');
    }
};
