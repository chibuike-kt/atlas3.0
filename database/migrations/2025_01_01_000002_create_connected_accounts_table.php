<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('mono_account_id')->unique();   // Mono's account ID
            $table->string('mono_auth_code')->nullable();  // Mono auth code (temporary)
            $table->string('institution');                 // Bank name
            $table->string('bank_code', 20)->nullable();
            $table->string('account_name');
            $table->string('account_number', 20);
            $table->enum('account_type', ['current', 'savings', 'wallet'])->default('current');
            $table->bigInteger('balance')->default(0);     // In kobo
            $table->string('currency', 5)->default('NGN');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('mono_account_id');
            $table->index(['user_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_accounts');
    }
};
