<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_servers', function (Blueprint $table) {
            $table->string('catalog_id')->nullable()->after('workspace_id'); // e.g. gmail, notion; null for custom servers
            $table->string('auth_type')->default('token')->after('url'); // token | oauth
            $table->text('encrypted_oauth')->nullable()->after('encrypted_token'); // access/refresh tokens + client, vault-encrypted
        });
    }

    public function down(): void
    {
        Schema::table('mcp_servers', function (Blueprint $table) {
            $table->dropColumn(['catalog_id', 'auth_type', 'encrypted_oauth']);
        });
    }
};
