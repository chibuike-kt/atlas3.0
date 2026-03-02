<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('execution_id')->nullable();
            $table->uuid('step_id')->nullable();
            $table->enum('entry_type', ['debit', 'credit', 'fee', 'refund', 'reversal']);
            $table->string('description');
            $table->bigInteger('amount');                 // In kobo — always positive
            $table->string('currency', 5)->default('NGN');
            $table->bigInteger('running_balance')->nullable(); // User balance after this entry in kobo
            $table->string('reference')->unique();
            $table->string('counterpart_reference')->nullable(); // Links debit to credit
            $table->json('meta')->nullable();
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamps();

            $table->index('user_id');
            $table->index('execution_id');
            $table->index('entry_type');
            $table->index('posted_at');
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
