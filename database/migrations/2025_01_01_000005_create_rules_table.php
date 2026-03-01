<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connected_account_id')->constrained();
            $table->string('name');
            $table->text('rule_text')->nullable();            // Original NLP input
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'paused', 'archived', 'draft'])->default('active');
            $table->enum('trigger_type', ['schedule', 'deposit', 'balance', 'manual', 'salary']);
            $table->json('trigger_config');                   // { frequency, time, day, ... }
            $table->enum('total_amount_type', ['fixed', 'percentage', 'remainder']);
            $table->bigInteger('total_amount')->nullable();   // In kobo — null for percentage/remainder
            $table->json('actions');                          // Array of action objects
            $table->boolean('is_ai_suggested')->default(false);
            $table->integer('execution_count')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('fail_count')->default(0);
            $table->bigInteger('total_amount_moved')->default(0); // In kobo
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamp('next_trigger_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('status');
            $table->index('trigger_type');
            $table->index('next_trigger_at');
            $table->index(['status', 'trigger_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rules');
    }
};
