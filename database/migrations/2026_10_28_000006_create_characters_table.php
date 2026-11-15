<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('appearance'); // the locked identity: face, hair, skin, build, outfit, distinguishing marks
            $table->string('style')->default('photorealistic');
            $table->text('voice')->nullable(); // how the character sounds in videos
            $table->json('reference_asset_ids')->nullable(); // uploaded photos / approved images of the character
            $table->foreignId('sheet_asset_id')->nullable()->constrained('assets')->nullOnDelete(); // generated character sheet
            $table->string('sheet_status')->default('none'); // none | generating | ready | failed
            $table->string('sheet_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
