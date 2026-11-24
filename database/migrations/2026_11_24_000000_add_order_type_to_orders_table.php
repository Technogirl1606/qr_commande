<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Type de commande : sur_table, a_emporter, livraison
            $table->string('order_type')->default('sur_table')->after('table_label');
            // Infos client (obligatoires pour livraison et à emporter)
            $table->string('customer_name')->nullable()->after('order_type');
            $table->string('customer_phone')->nullable()->after('customer_name');
            $table->string('customer_district')->nullable()->after('customer_phone'); // Quartier (livraison uniquement)
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['order_type', 'customer_name', 'customer_phone', 'customer_district']);
        });
    }
};
