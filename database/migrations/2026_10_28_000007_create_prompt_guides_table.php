<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How the AI prompt writer should write prompts for this workspace ("training").
        Schema::create('prompt_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('task_type'); // image | video
            $table->text('instructions')->nullable();
            $table->json('examples')->nullable(); // [{prompt, note, saved_at}] prompts the user liked
            $table->foreignId('credential_id')->nullable()->constrained('ai_credentials')->nullOnDelete();
            $table->string('model')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'task_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_guides');
    }
};
