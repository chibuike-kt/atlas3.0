<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('execution_id')->constrained('rule_executions');
            $table->string('dispute_number')->unique();     // DSP-2025-00001
            $table->enum('reason', [
                'not_authorised',
                'wrong_amount',
                'wrong_recipient',
                'duplicate',
                'service_not_received',
                'technical_error',
                'other',
            ]);
            $table->text('description');
            $table->enum('status', [
                'open',
                'under_review',
                'resolved_refund',
                'resolved_no_action',
                'closed',
            ])->default('open');
            $table->bigInteger('amount_disputed')->nullable();   // In kobo
            $table->bigInteger('refund_amount')->nullable();     // In kobo
            $table->text('resolution_note')->nullable();
            $table->uuid('resolved_by')->nullable();             // Admin user ID
            $table->timestamp('opened_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('execution_id');
            $table->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
