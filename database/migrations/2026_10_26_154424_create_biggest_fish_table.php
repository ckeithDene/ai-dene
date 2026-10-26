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
        Schema::create('biggest_fish', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('odoo_partner_id')->unique();
            $table->string('company_name');
            $table->boolean('is_registered_company')->default(true);
            $table->text('address')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('overview')->nullable();
            $table->string('company_size')->nullable();
            $table->string('estimated_value')->nullable();
            $table->unsignedBigInteger('estimated_value_gbp')->nullable()->index();
            $table->string('market_share')->nullable();
            $table->decimal('total_spent', 15, 2)->default(0);
            $table->string('currency', 16)->nullable();
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
        Schema::dropIfExists('biggest_fish');
    }
};
