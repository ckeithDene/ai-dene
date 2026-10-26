<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('first_order_diamonds', function (Blueprint $table) {
            $table->boolean('is_registered_company')->default(true)->after('company_name');
            $table->string('website')->nullable()->after('address');
            $table->json('order_lines')->nullable()->after('order_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('first_order_diamonds', function (Blueprint $table) {
            $table->dropColumn(['is_registered_company', 'website', 'order_lines']);
        });
    }
};
