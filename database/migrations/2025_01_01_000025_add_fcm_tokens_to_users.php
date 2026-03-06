<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('users', function (Blueprint $table) {
      if (! Schema::hasColumn('users', 'fcm_tokens')) {
        $table->json('fcm_tokens')->nullable()->after('suspension_reason');
      }
    });
  }

  public function down(): void
  {
    Schema::table('users', function (Blueprint $table) {
      $table->dropColumn('fcm_tokens');
    });
  }
};
