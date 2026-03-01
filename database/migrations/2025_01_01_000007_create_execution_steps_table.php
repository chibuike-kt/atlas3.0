<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('execution_id')->constrained('rule_executions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->integer('step_order');
            $table->enum('action_type', ['send_bank', 'save_piggyvest', 'save_cowrywise', 'convert_crypto', 'pay_bill']);
            $table->string('label')->nullable();
            $table->bigInteger('amount');                   // In kobo
            $table->string('currency', 5)->default('NGN');
            $table->enum('amount_type', ['fixed', 'percentage', 'remainder']);
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'rolled_back', 'skipped'])
                  ->default('pending');
            $table->string('rail_reference')->nullable();   // External provider reference
            $table->string('failure_reason')->nullable();
            $table->boolean('rolled_back')->default(false);
            $table->string('rollback_reference')->nullable();
            $table->json('config');                         // Action-specific config
            $table->json('result')->nullable();             // External provider response
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->index('execution_id');
            $table->index('user_id');
            $table->index('step_order');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_steps');
    }
};
