<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');                        // How the user knows them: "Mama", "Landlord"
            $table->enum('type', ['bank', 'crypto']);
            // Bank contact fields
            $table->string('account_name')->nullable();
            $table->string('account_number', 20)->nullable();
            $table->string('bank_code', 20)->nullable();
            $table->string('bank_name')->nullable();
            // Crypto wallet fields
            $table->string('wallet_address')->nullable();
            $table->string('crypto_network')->nullable();   // bep20, trc20, etc.
            $table->string('wallet_label')->nullable();
            // Usage tracking
            $table->integer('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
