<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('conversation_key', 191);
            $table->string('idempotency_key', 191);
            $table->string('status');
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'conversation_key']);
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_proposals');
    }
};
