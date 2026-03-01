<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('phone', 20)->unique();
            $table->string('password');
            $table->string('pin_hash');                    // 4-digit transaction PIN (hashed)
            $table->string('avatar_url')->nullable();
            $table->enum('kyc_status', ['pending', 'submitted', 'verified', 'failed'])->default('pending');
            $table->json('kyc_data')->nullable();
            $table->string('bvn_hash')->nullable();        // BVN stored as hash only
            $table->boolean('is_active')->default(true);
            $table->boolean('notifications_enabled')->default(true);
            $table->json('notification_preferences')->nullable();
            $table->string('timezone')->default('Africa/Lagos');
            $table->string('currency')->default('NGN');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('email');
            $table->index('phone');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
