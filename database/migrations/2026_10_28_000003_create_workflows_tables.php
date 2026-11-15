<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('draft'); // draft | active | paused
            $table->json('draft_graph');
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('graph');
            $table->string('graph_hash', 64);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['workflow_id', 'version']);
        });

        Schema::create('executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('workflow_versions')->cascadeOnDelete();
            $table->string('status')->default('queued'); // queued | running | waiting | succeeded | failed
            $table->json('trigger_data')->nullable();
            $table->text('error')->nullable();
            $table->decimal('cost_estimate', 10, 4)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('execution_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->string('node_id');
            $table->string('node_type');
            $table->string('status')->default('queued'); // queued | running | waiting | succeeded | failed
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('state')->nullable(); // resume data for waiting steps (e.g. video operation name)
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('tokens')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['execution_id', 'node_id']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // image | video | audio
            $table->string('storage_path');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->json('tags')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
        Schema::dropIfExists('execution_steps');
        Schema::dropIfExists('executions');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflows');
    }
};
