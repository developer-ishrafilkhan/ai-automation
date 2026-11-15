<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The fields themselves live on the trigger node (so they are versioned with the flow);
        // the link and its public settings live on the workflow.
        Schema::table('workflows', function (Blueprint $table) {
            $table->string('form_slug', 32)->nullable()->unique()->after('active_version_id');
            $table->boolean('form_enabled')->default(false)->after('form_slug');
            $table->json('form_settings')->nullable()->after('form_enabled');
        });

        Schema::table('executions', function (Blueprint $table) {
            $table->string('source', 16)->default('editor')->after('status'); // editor | form
            $table->string('public_token', 64)->nullable()->unique()->after('source');
            $table->index(['workflow_id', 'source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropIndex(['workflow_id', 'source', 'created_at']);
            $table->dropColumn(['source', 'public_token']);
        });
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn(['form_slug', 'form_enabled', 'form_settings']);
        });
    }
};
