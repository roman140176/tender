<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->index()->constrained()->cascadeOnDelete();
            $table->string('status')->default('new')->index();
            $table->foreignId('assigned_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('best_score')->default(0)->index();
            $table->string('best_level')->default('low')->index();
            $table->timestamp('first_matched_at');
            $table->timestamps();
            $table->unique(['company_id', 'tender_id']);
        });

        Schema::create('tender_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('search_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('radar_run_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('score');
            $table->string('level');
            $table->jsonb('reason')->default('[]');
            $table->jsonb('matched_keywords')->default('[]');
            $table->jsonb('matched_negative_keywords')->default('[]');
            $table->jsonb('risk_flags')->default('[]');
            $table->string('action_recommendation');
            $table->jsonb('ai_assessment')->nullable();
            $table->timestamps();
            $table->unique(['search_profile_id', 'tender_id']);
        });

        Schema::create('tender_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained();
            $table->text('comment');
            $table->timestamp('created_at');
        });

        Schema::create('tender_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_status_history');
        Schema::dropIfExists('tender_comments');
        Schema::dropIfExists('tender_matches');
        Schema::dropIfExists('company_tenders');
    }
};
