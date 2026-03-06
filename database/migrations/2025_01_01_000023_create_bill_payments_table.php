<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('bill_payments', function (Blueprint $table) {
      $table->uuid('id')->primary();
      $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
      $table->foreignUuid('connected_account_id')->constrained('connected_accounts');
      $table->string('bill_type');           // airtime, data, electricity, cable
      $table->string('provider');            // mtn, dstv, ikeja, etc.
      $table->string('variation_code')->nullable();
      $table->string('biller_code')->nullable();  // meter number, smart card
      $table->string('phone')->nullable();
      $table->bigInteger('amount');          // in kobo
      $table->bigInteger('fee')->default(0);
      $table->string('reference')->unique();
      $table->string('provider_reference')->nullable();
      $table->string('status')->default('successful');
      $table->string('token')->nullable();   // electricity token
      $table->json('response_data')->nullable();
      $table->timestamp('paid_at')->nullable();
      $table->timestamps();

      $table->index('user_id');
      $table->index('bill_type');
      $table->index('status');
      $table->index('paid_at');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('bill_payments');
  }
};
