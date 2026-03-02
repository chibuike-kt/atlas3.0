<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('execution_id')->constrained('rule_executions')->cascadeOnDelete();
            $table->string('receipt_number')->unique();     // ATL-2025-00001
            $table->string('rule_name');
            $table->bigInteger('total_amount');             // In kobo
            $table->bigInteger('total_fee');                // In kobo
            $table->bigInteger('total_debited');            // In kobo
            $table->string('currency', 5)->default('NGN');
            $table->enum('status', ['completed', 'failed', 'partial']);
            $table->json('steps_summary');                  // Snapshot of each step
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamps();

            $table->index('user_id');
            $table->index('execution_id');
            $table->index('receipt_number');
            $table->index('issued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
