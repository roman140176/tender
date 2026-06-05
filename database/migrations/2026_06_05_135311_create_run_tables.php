<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_collect_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->index()->constrained();
            $table->string('trigger');
            $table->string('status')->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('fetched_count')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('log')->nullable();
            $table->timestamps();
        });

        Schema::create('radar_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('search_profile_id')->index()->constrained()->cascadeOnDelete();
            $table->string('trigger');
            $table->string('status')->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('notified_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('log')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radar_runs');
        Schema::dropIfExists('source_collect_runs');
    }
};
