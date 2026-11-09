<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_tool_calls', function (Blueprint $table): void {
            $table->dropUnique('conv_tool_calls_conv_provider_uc');
            // Provider call ids may repeat across turns; scope uniqueness to the user turn.
            $table->unique(['conversation_id', 'user_message_id', 'provider_tool_call_id'], 'conv_tool_calls_conv_user_msg_provider_uc');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_tool_calls', function (Blueprint $table): void {
            $table->dropUnique('conv_tool_calls_conv_user_msg_provider_uc');
            $table->unique(['conversation_id', 'provider_tool_call_id'], 'conv_tool_calls_conv_provider_uc');
        });
    }
};
