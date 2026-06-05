<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->jsonb('law_types')->default('[]');
            $table->jsonb('regions')->default('[]');
            $table->jsonb('excluded_regions')->default('[]');
            $table->jsonb('okpd2_codes')->default('[]');
            $table->decimal('min_price', 15, 2)->nullable();
            $table->decimal('max_price', 15, 2)->nullable();
            $table->unsignedSmallInteger('deadline_min_days')->default(0);
            $table->unsignedSmallInteger('notification_threshold')->default(60);
            $table->boolean('exclude_on_negative_in_title')->default(false);
            $table->jsonb('scoring_weights')->nullable();
            $table->string('schedule_type')->default('manual');
            $table->string('schedule_cron')->nullable();
            $table->string('timezone')->default('Europe/Moscow');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_profile_id')->constrained()->cascadeOnDelete();
            $table->string('value');
            $table->string('type');
            $table->string('origin')->default('manual');
            $table->timestamps();
            $table->unique(['search_profile_id', 'value', 'type']);
        });

        Schema::create('synonyms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->string('term');
            $table->jsonb('variants')->default('[]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synonyms');
        Schema::dropIfExists('keywords');
        Schema::dropIfExists('search_profiles');
    }
};
