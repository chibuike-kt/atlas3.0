<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix 1: Change action_type on execution_steps from enum to string
        // The enum was missing 'save_piggvest' and is too rigid for future action types
        Schema::table('execution_steps', function (Blueprint $table) {
            $table->string('action_type', 50)->change();
            $table->string('amount_type', 20)->change();
        });

        // Fix 2: Change failure_reason on rule_executions from VARCHAR to TEXT
        // Error messages can be long (especially SQL errors during debugging)
        Schema::table('rule_executions', function (Blueprint $table) {
            $table->text('failure_reason')->nullable()->change();
        });

        // Fix 3: Change failure_reason on execution_steps to TEXT as well
        Schema::table('execution_steps', function (Blueprint $table) {
            $table->text('failure_reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('execution_steps', function (Blueprint $table) {
            $table->enum('action_type', ['send_bank', 'save_piggvest', 'save_cowrywise', 'convert_crypto', 'pay_bill'])->change();
            $table->enum('amount_type', ['fixed', 'percentage', 'remainder'])->change();
            $table->string('failure_reason')->nullable()->change();
        });

        Schema::table('rule_executions', function (Blueprint $table) {
            $table->string('failure_reason')->nullable()->change();
        });
    }
};
