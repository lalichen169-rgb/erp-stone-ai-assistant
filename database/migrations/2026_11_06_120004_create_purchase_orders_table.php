<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('status');
            $table->date('order_date');
            $table->date('eta')->nullable();
            $table->decimal('freight_cost', 12, 2)->default(0);
            $table->timestamps();

            $table->index('status');
            $table->index('order_date');
            $table->index('eta');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
