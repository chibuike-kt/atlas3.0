<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('contacts', function (Blueprint $table) {
      if (! Schema::hasColumn('contacts', 'is_favourite')) {
        $table->boolean('is_favourite')->default(false)->after('bank_name');
      }
    });
  }

  public function down(): void
  {
    Schema::table('contacts', function (Blueprint $table) {
      $table->dropColumn('is_favourite');
    });
  }
};
