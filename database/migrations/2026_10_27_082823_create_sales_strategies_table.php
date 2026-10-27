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
        Schema::create('sales_strategies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('odoo_partner_id')->unique();
            $table->string('company_name');
            $table->string('status')->default('pending');
            $table->text('failure_reason')->nullable();
            $table->json('research')->nullable();
            $table->json('report')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_strategies');
    }
};
