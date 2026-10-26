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
        Schema::create('first_order_diamonds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('odoo_partner_id')->unique();
            $table->unsignedBigInteger('odoo_order_id');
            $table->string('order_name');
            $table->timestamp('ordered_at')->nullable();
            $table->string('company_name');
            $table->text('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('overview')->nullable();
            $table->string('company_size')->nullable();
            $table->string('estimated_value')->nullable();
            $table->string('market_share')->nullable();
            $table->string('status')->default('pending');
            $table->text('failure_reason')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('first_order_diamonds');
    }
};
