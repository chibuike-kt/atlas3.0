<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisory_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');                         // InsightType enum value
            $table->string('title');
            $table->text('body');                           // The insight message shown to user
            $table->integer('priority')->default(5);        // 1 = most urgent
            $table->boolean('is_urgent')->default(false);
            $table->boolean('is_read')->default(false);
            $table->boolean('is_dismissed')->default(false);
            $table->boolean('is_actioned')->default(false);  // User clicked "Do this"
            $table->json('action_payload')->nullable();      // Suggested rule or action data
            $table->json('data')->nullable();                // Supporting numbers/context
            $table->string('cta_label')->nullable();         // Call-to-action button text
            $table->string('cta_action')->nullable();        // What the CTA does: 'create_rule', 'move_funds', etc.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('type');
            $table->index('is_read');
            $table->index('is_urgent');
            $table->index('priority');
            $table->index(['user_id', 'is_read', 'is_dismissed']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisory_insights');
    }
};
