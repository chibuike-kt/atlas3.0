<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('network');                      // bep20, trc20, etc.
            $table->string('token', 10)->default('USDT');
            $table->string('deposit_address');
            $table->decimal('balance', 20, 8)->default(0);
            $table->decimal('total_deposited', 20, 8)->default(0);
            $table->decimal('total_withdrawn', 20, 8)->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'network', 'token']);
            $table->index('user_id');
            $table->index('network');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_wallets');
    }
};
