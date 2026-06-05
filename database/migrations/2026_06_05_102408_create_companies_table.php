<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('inn', 12)->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('industries')->default('[]');
            $table->jsonb('products')->default('[]');
            $table->jsonb('services')->default('[]');
            $table->jsonb('contacts')->default('[]');
            $table->string('notification_email')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->string('crm_webhook_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
