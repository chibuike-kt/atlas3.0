<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();

            // Salary intelligence
            $table->boolean('salary_detected')->default(false);
            $table->integer('salary_day')->nullable();          // Day of month salary typically arrives
            $table->bigInteger('average_salary')->nullable();   // In kobo — rolling 3-month average
            $table->bigInteger('last_salary_amount')->nullable();
            $table->date('last_salary_date')->nullable();
            $table->string('salary_source')->nullable();        // Employer name if detectable
            $table->decimal('salary_consistency_score', 4, 2)->nullable(); // 0-100

            // Spending intelligence
            $table->bigInteger('avg_monthly_income')->nullable();
            $table->bigInteger('avg_monthly_spend')->nullable();
            $table->bigInteger('avg_monthly_savings')->nullable();
            $table->decimal('savings_rate_percent', 5, 2)->nullable();
            $table->json('spend_by_category')->nullable();      // { food: 45000, transport: 12000, ... } in kobo
            $table->json('spend_trend_monthly')->nullable();    // Last 6 months of totals
            $table->json('income_sources')->nullable();         // Detected income sources

            // Cashflow projection
            $table->bigInteger('projected_eom_balance')->nullable(); // End of month projection in kobo
            $table->decimal('cashflow_volatility_score', 4, 2)->nullable(); // 0-100, higher = more volatile
            $table->enum('income_type', ['salaried', 'freelance', 'trader', 'mixed', 'unknown'])->default('unknown');

            // Personal inflation
            $table->decimal('personal_inflation_rate', 5, 2)->nullable(); // User's own inflation vs last month
            $table->json('inflation_by_category')->nullable();

            // Financial health
            $table->decimal('financial_health_score', 4, 2)->nullable(); // 0-100
            $table->json('health_score_breakdown')->nullable();

            // Ajo/esusu
            $table->boolean('has_ajo_activity')->default(false);
            $table->bigInteger('monthly_ajo_obligation')->nullable(); // In kobo

            // Meta
            $table->timestamp('last_analyzed_at')->nullable();
            $table->integer('transactions_analyzed')->default(0);
            $table->timestamps();

            $table->index('user_id');
            $table->index('salary_detected');
            $table->index('last_analyzed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_profiles');
    }
};
