<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_message_id')->constrained('conversation_messages')->restrictOnDelete();
            $table->foreignId('tool_result_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->unsignedInteger('execution_order')->default(0);
            $table->string('provider_tool_call_id', 191);
            $table->string('tool_name', 128);
            $table->json('arguments');
            $table->string('status', 32)->default('completed');
            $table->json('safe_result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'user_message_id']);
            $table->index(['conversation_id', 'execution_order']);
            // MySQL 5.7 max identifier length is 64 chars; Laravel default name exceeds it.
            // Uniqueness is scoped per user turn in a follow-up migration (provider ids may repeat across turns).
            $table->unique(['conversation_id', 'provider_tool_call_id'], 'conv_tool_calls_conv_provider_uc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_tool_calls');
    }
};
