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
        Schema::table('odoo_connections', function (Blueprint $table) {
            $table->string('company')->default('midmeds');
        });

        Schema::table('odoo_connections', function (Blueprint $table) {
            $table->unique('company');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('odoo_connections', function (Blueprint $table) {
            $table->dropUnique(['company']);
            $table->dropColumn('company');
        });
    }
};
