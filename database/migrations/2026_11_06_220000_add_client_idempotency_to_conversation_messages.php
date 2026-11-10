<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            /**
             * Optional HTTP Idempotency-Key (Phase 9): scoped per conversation; prevents duplicate
             * user rows and allows safe replay of the same assistant response after retries.
             */
            $table->string('client_idempotency_key', 128)->nullable()->after('turn_idempotency_key');
            /** Cached JSON for safe HTTP replay (success or safe failure payload). */
            $table->json('idempotency_response')->nullable()->after('client_idempotency_key');
            $table->unique(['conversation_id', 'client_idempotency_key'], 'conv_msgs_conv_client_idem_uc');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropUnique('conv_msgs_conv_client_idem_uc');
            $table->dropColumn(['client_idempotency_key', 'idempotency_response']);
        });
    }
};
