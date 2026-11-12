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
        Schema::create('google_shopping_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('odoo_product_id')->unique();
            $table->string('product_name');
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->decimal('units_sold', 15, 2)->default(0)->index();
            $table->decimal('sales_price', 12, 2)->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('cheapest_price', 12, 2)->nullable();
            $table->string('cheapest_seller')->nullable();
            $table->string('cheapest_url', 2048)->nullable();
            $table->string('matched_product')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('beats_sales_price')->default(false)->index();
            $table->boolean('beats_cost_price')->default(false)->index();
            $table->decimal('potential_margin', 12, 2)->nullable();
            $table->decimal('potential_margin_percent', 6, 2)->nullable()->index();
            $table->string('status')->default('pending');
            $table->text('failure_reason')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('google_shopping_scans', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('next_rank')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_shopping_scans');
        Schema::dropIfExists('google_shopping_suggestions');
    }
};
