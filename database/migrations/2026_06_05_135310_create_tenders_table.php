<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained();
            $table->string('external_id');
            $table->string('number')->nullable();
            $table->string('source_url', 2048);
            $table->text('title');
            $table->text('description')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_inn', 12)->nullable();
            $table->string('region')->nullable()->index();
            $table->decimal('price', 15, 2)->nullable()->index();
            $table->string('currency', 3)->default('RUB');
            $table->string('law_type')->default('other')->index();
            $table->string('procedure_type')->nullable();
            $table->string('source_status')->default('active');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('deadline_at')->nullable()->index();
            $table->jsonb('okpd2')->default('[]');
            $table->jsonb('ktru')->default('[]');
            $table->jsonb('extra')->default('{}');
            $table->string('content_hash', 64)->index();
            $table->foreignId('owner_company_id')->nullable()->index()->constrained('companies')->cascadeOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['source_id', 'external_id']);
            $table->index('source_url');
            $table->index('updated_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tenders ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
              setweight(to_tsvector('russian'::regconfig, coalesce(title, '')), 'A') ||
              setweight(to_tsvector('russian'::regconfig, coalesce(description, '')), 'B')
            ) STORED
        SQL);
        DB::statement('CREATE INDEX tenders_search_vector_gin ON tenders USING GIN (search_vector)');
        DB::statement('CREATE INDEX tenders_title_trgm ON tenders USING GIN (title gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenders');
    }
};
