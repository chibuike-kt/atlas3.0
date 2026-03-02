<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('execution_id')->constrained('rule_executions')->cascadeOnDelete();
            $table->enum('fee_type', ['execution', 'fx_spread', 'crypto_flat', 'salary_advance', 'withdrawal']);
            $table->bigInteger('amount');                   // In kobo
            $table->string('currency', 5)->default('NGN');
            $table->string('description');
            $table->json('breakdown')->nullable();          // Fee calculation details
            $table->timestamp('charged_at')->useCurrent();
            $table->timestamps();

            $table->index('user_id');
            $table->index('execution_id');
            $table->index('fee_type');
            $table->index('charged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_ledger');
    }
};
