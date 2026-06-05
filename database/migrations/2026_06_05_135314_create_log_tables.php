<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->foreignId('search_profile_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('channel');
            $table->string('recipient');
            $table->string('status')->default('queued');
            $table->jsonb('payload')->default('{}');
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'tender_id', 'search_profile_id', 'channel'], 'notification_dedup_idx');
        });

        Schema::create('ai_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('model');
            $table->text('summary');
            $table->jsonb('requirements')->default('[]');
            $table->jsonb('risks')->default('[]');
            $table->unsignedInteger('tokens_used')->default(0);
            $table->timestamp('created_at');
        });

        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedBigInteger('tokens_used')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'period']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_summaries');
        Schema::dropIfExists('notification_logs');
    }
};
