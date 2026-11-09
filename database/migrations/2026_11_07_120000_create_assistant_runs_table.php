<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_message_id')->unique()->constrained('conversation_messages')->cascadeOnDelete();
            $table->string('status', 32);
            $table->text('original_message');
            $table->string('client_idempotency_key')->nullable();
            $table->char('request_hash', 64);
            $table->json('result_payload')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['conversation_id', 'client_idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_runs');
    }
};
