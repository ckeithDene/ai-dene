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
        Schema::table('nhs_catalogue_gaps', function (Blueprint $table) {
            $table->json('matched_categories')->nullable();
            $table->string('catalogue_status')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhs_catalogue_gaps', function (Blueprint $table) {
            $table->dropColumn(['matched_categories', 'catalogue_status']);
        });
    }
};
