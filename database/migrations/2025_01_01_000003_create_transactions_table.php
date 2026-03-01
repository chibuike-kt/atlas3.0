<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connected_account_id')->constrained()->cascadeOnDelete();
            $table->string('mono_transaction_id')->nullable()->unique();
            $table->enum('type', ['credit', 'debit']);
            $table->bigInteger('amount');               // In kobo
            $table->bigInteger('balance_after')->nullable(); // In kobo
            $table->string('currency', 5)->default('NGN');
            $table->string('description')->nullable();
            $table->string('narration')->nullable();    // Raw bank narration
            $table->string('reference')->nullable();
            // Categorisation
            $table->string('category')->nullable();     // food, transport, utilities, etc.
            $table->string('sub_category')->nullable();
            $table->boolean('is_salary')->default(false);
            $table->boolean('is_family_transfer')->default(false);
            $table->boolean('is_ajo')->default(false);
            $table->boolean('is_bill_payment')->default(false);
            $table->boolean('is_atlas_execution')->default(false);
            $table->decimal('confidence_score', 4, 2)->nullable(); // Categorisation confidence
            // Counterparty
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_account')->nullable();
            $table->string('counterparty_bank')->nullable();
            // Timing
            $table->date('transaction_date');
            $table->timestamp('processed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('connected_account_id');
            $table->index('transaction_date');
            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'is_salary']);
            $table->index(['user_id', 'category']);
            $table->index('mono_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
