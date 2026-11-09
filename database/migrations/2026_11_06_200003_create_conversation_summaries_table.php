<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->longText('content');
            $table->foreignId('last_summarized_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->unsignedInteger('covered_message_count')->default(0);
            $table->unsignedInteger('last_summarized_sequence')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_summaries');
    }
};
