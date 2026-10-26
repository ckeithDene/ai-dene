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
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_error')->nullable();
        });

        Schema::table('ai_models', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('odoo_connections', function (Blueprint $table) {
            $table->dropColumn(['verified_at', 'verification_error']);
        });

        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropColumn(['verified_at', 'verification_error']);
        });
    }
};
