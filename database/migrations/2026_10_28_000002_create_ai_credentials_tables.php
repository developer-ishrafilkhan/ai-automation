<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // anthropic | openai | google | deepseek
            $table->string('label');
            $table->text('encrypted_key');
            $table->string('key_last4', 4);
            $table->string('status')->default('untested'); // untested | valid | invalid
            $table->string('status_message')->nullable();
            $table->json('models')->nullable(); // fetched from the provider, not hardcoded
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('model_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('task_type'); // text | research | image | video
            $table->foreignId('credential_id')->nullable()->constrained('ai_credentials')->nullOnDelete();
            $table->string('model')->nullable();
            $table->foreignId('fallback_credential_id')->nullable()->constrained('ai_credentials')->nullOnDelete();
            $table->string('fallback_model')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'task_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_defaults');
        Schema::dropIfExists('ai_credentials');
    }
};
