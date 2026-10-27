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
        Schema::create('sales_engine_settings', function (Blueprint $table) {
            $table->id();
            $table->string('objective')->default('balanced');
            $table->string('tone')->default('ai');
            $table->string('research_depth')->default('comprehensive');
            $table->boolean('suggest_account_pricing')->default(true);
            $table->boolean('promote_repeat_ordering')->default(true);
            $table->boolean('evaluate_pharmaceuticals')->default(true);
            $table->unsignedTinyInteger('max_products')->default(8);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_engine_settings');
    }
};
