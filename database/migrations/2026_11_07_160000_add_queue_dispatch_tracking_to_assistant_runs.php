<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_runs', function (Blueprint $table) {
            $table->timestamp('last_queue_dispatch_at')->nullable()->after('request_hash');
            $table->unsignedSmallInteger('queue_dispatch_attempts')->default(0)->after('last_queue_dispatch_at');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_runs', function (Blueprint $table) {
            $table->dropColumn(['last_queue_dispatch_at', 'queue_dispatch_attempts']);
        });
    }
};
