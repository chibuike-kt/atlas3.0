<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('contacts', function (Blueprint $table) {
      // Add missing name column after user_id
      if (! Schema::hasColumn('contacts', 'name')) {
        $table->string('name')->after('user_id');
      }

      // Make label nullable — it's optional
      $table->string('label')->nullable()->change();
    });
  }

  public function down(): void
  {
    Schema::table('contacts', function (Blueprint $table) {
      $table->dropColumn('name');
      $table->string('label')->nullable(false)->change();
    });
  }
};
