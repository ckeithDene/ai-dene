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
        Schema::create('nhs_directives', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('title');
            $table->string('issuer')->nullable();
            $table->string('published_on')->nullable();
            $table->string('updated_on')->nullable();
            $table->string('effective_on')->nullable();
            $table->string('obligation');
            $table->string('applies_to')->nullable();
            $table->string('source_title')->nullable();
            $table->string('source_url')->nullable();
            $table->json('sources')->nullable();
            $table->text('summary')->nullable();
            $table->text('commercial_implications')->nullable();
            $table->string('confidence')->default('Low');
            $table->boolean('requirement_confirmed')->default(false);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('nhs_product_opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('demand_direction');
            $table->string('timeframe')->nullable();
            $table->text('explanation')->nullable();
            $table->text('commercial_action')->nullable();
            $table->text('secondary_demand')->nullable();
            $table->text('sales_proposition')->nullable();
            $table->unsignedTinyInteger('opportunity_score')->default(0);
            $table->string('confidence')->default('Low');
            $table->json('search_terms')->nullable();
            $table->boolean('requirement_confirmed')->default(false);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('nhs_directive_opportunity', function (Blueprint $table) {
            $table->foreignId('nhs_directive_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nhs_product_opportunity_id')->constrained()->cascadeOnDelete();
            $table->primary(['nhs_directive_id', 'nhs_product_opportunity_id']);
        });

        Schema::create('nhs_catalogue_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhs_product_opportunity_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('gap_type');
            $table->string('coverage');
            $table->string('sourcing_action');
            $table->unsignedTinyInteger('gap_score')->nullable();
            $table->text('recommendation')->nullable();
            $table->text('missing_items')->nullable();
            $table->json('matched_products')->nullable();
            $table->timestamps();
        });

        Schema::create('nhs_purchasing_settings', function (Blueprint $table) {
            $table->id();
            $table->text('last_summary')->nullable();
            $table->text('last_changes')->nullable();
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
        Schema::dropIfExists('nhs_purchasing_settings');
        Schema::dropIfExists('nhs_catalogue_gaps');
        Schema::dropIfExists('nhs_directive_opportunity');
        Schema::dropIfExists('nhs_product_opportunities');
        Schema::dropIfExists('nhs_directives');
    }
};
