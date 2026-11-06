<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_ai_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('model', 128);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedSmallInteger('tool_iterations')->default(0);
            $table->unsignedSmallInteger('tool_calls_executed')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('completed')->default(true);
            $table->string('error_code', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_ai_interactions');
    }
};
