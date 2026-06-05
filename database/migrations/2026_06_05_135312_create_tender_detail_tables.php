<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('source_collect_run_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('tender_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('url', 2048);
            $table->string('file_type')->nullable();
            $table->string('local_path')->nullable();
            $table->text('parsed_text')->nullable();
            $table->timestamp('created_at');
            $table->unique(['tender_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_attachments');
        Schema::dropIfExists('tender_changes');
    }
};
