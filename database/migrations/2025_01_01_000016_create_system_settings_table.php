<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->text('value');
            $table->string('type')->default('string');     // string, integer, float, boolean, json
            $table->string('description')->nullable();
            $table->boolean('is_public')->default(false);  // Exposed to frontend?
            $table->timestamps();

            $table->index('key');
            $table->index('is_public');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
