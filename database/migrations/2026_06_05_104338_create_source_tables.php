<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('base_url')->nullable();
            $table->string('connection_type');
            $table->string('adapter_class')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('default_config')->default('{}');
            $table->string('schedule_cron')->nullable();
            $table->timestamp('last_collected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('company_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->index()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('config')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_sources');
        Schema::dropIfExists('sources');
    }
};
