<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
        $table->string('public_id', 26)->nullable()->unique();
        $table->string('payment_status')->default('en_attente');
        $table->string('payment_reference')->nullable()->index();
        $table->text('payment_url')->nullable();
        $table->timestamp('paid_at')->nullable();
    });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['public_id', 'payment_status', 'payment_reference', 'payment_url', 'paid_at']);
        });
    }
};
