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
        Schema::create('gp_premises_leads', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('surgery_name');
            $table->string('website')->nullable();
            $table->string('town')->nullable();
            $table->string('county')->nullable();
            $table->string('nation');
            $table->string('development_type');
            $table->text('development_details')->nullable();
            $table->string('project_stage');
            $table->unsignedTinyInteger('stage_rank')->default(6);
            $table->string('status');
            $table->string('source_title')->nullable();
            $table->string('source_published_on')->nullable();
            $table->string('source_url')->nullable();
            $table->json('sources')->nullable();
            $table->string('project_dates')->nullable();
            $table->string('funding')->nullable();
            $table->string('planning_reference')->nullable();
            $table->string('planning_authority')->nullable();
            $table->string('source_confidence')->default('Low');
            $table->text('fit_out_opportunity')->nullable();
            $table->string('sales_timing')->nullable();
            $table->string('sales_priority')->default('low');
            $table->unsignedTinyInteger('opportunity_score')->default(0);
            $table->text('next_action')->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gp_premises_leads');
    }
};
