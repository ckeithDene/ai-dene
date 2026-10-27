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
        Schema::create('gp_premises_settings', function (Blueprint $table) {
            $table->id();
            $table->string('geography')->default('uk');
            $table->unsignedSmallInteger('source_window_months')->default(12);
            $table->unsignedSmallInteger('future_horizon_months')->default(24);
            $table->json('development_types');
            $table->boolean('high_priority_only')->default(false);
            $table->text('last_summary')->nullable();
            $table->text('last_limitations')->nullable();
            $table->timestamp('last_ran_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gp_premises_settings');
    }
};
