<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rule_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connected_account_id')->constrained();
            $table->string('idempotency_key')->nullable()->unique();
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'rolled_back', 'skipped'])
                  ->default('pending');
            $table->enum('trigger_type', ['schedule', 'deposit', 'balance', 'manual', 'salary']);
            $table->bigInteger('total_amount')->default(0);       // In kobo
            $table->bigInteger('total_fee')->default(0);          // In kobo
            $table->bigInteger('total_debited')->default(0);      // Amount + fee in kobo
            $table->integer('steps_total')->default(0);
            $table->integer('steps_completed')->default(0);
            $table->integer('steps_failed')->default(0);
            $table->string('failure_reason')->nullable();
            $table->boolean('rolled_back')->default(false);
            $table->bigInteger('balance_before')->nullable();     // In kobo
            $table->bigInteger('balance_after')->nullable();      // In kobo
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('rule_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_executions');
    }
};
