<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->string('role', 16);
            $table->longText('content')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_internal')->default(false);
            /** Server-side idempotency for the user turn (proposal tool, etc.) */
            $table->string('turn_idempotency_key', 80)->nullable()->unique();
            $table->timestamps();

            $table->index(['conversation_id', 'sequence']);
            $table->index(['conversation_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
