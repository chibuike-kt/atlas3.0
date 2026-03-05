<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('salary_advances', function (Blueprint $table) {
      $table->uuid('id')->primary();
      $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
      $table->foreignUuid('connected_account_id')->constrained('connected_accounts');
      $table->bigInteger('amount');               // Amount disbursed in kobo
      $table->bigInteger('fee');                  // Fee charged in kobo
      $table->bigInteger('repayment_amount');     // amount + fee in kobo
      $table->bigInteger('repaid_amount')->nullable();
      $table->string('status')->default('pending'); // pending, disbursed, repaid, defaulted
      $table->integer('expected_salary_day');
      $table->date('due_date');
      $table->timestamp('requested_at')->useCurrent();
      $table->timestamp('disbursed_at')->nullable();
      $table->timestamp('repaid_at')->nullable();
      $table->timestamps();

      $table->index('user_id');
      $table->index('status');
      $table->index('due_date');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('salary_advances');
  }
};
