<?php

namespace Database\Factories;

use App\Enums\CustomerReviewStatus;
use App\Models\GoogleShoppingSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleShoppingSuggestion>
 */
class GoogleShoppingSuggestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'odoo_product_id' => fake()->unique()->numberBetween(1, 100000),
            'product_name' => 'Nitrile Gloves Medium (Box of 100)',
            'sku' => 'GLV-M-100',
            'barcode' => null,
            'units_sold' => '500.00',
            'sales_price' => '5.00',
            'cost_price' => '3.00',
            'cheapest_price' => '7.20',
            'cheapest_seller' => 'Medisave',
            'cheapest_url' => 'https://medisave.example/gloves',
            'matched_product' => 'Nitrile Gloves Medium x100',
            'notes' => null,
            'beats_sales_price' => true,
            'beats_cost_price' => true,
            'potential_margin' => '2.99',
            'potential_margin_percent' => '49.93',
            'status' => CustomerReviewStatus::Complete,
            'failure_reason' => null,
            'checked_at' => now(),
        ];
    }
}
