<?php

namespace Database\Factories;

use App\Enums\CustomerReviewStatus;
use App\Models\BiggestFish;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BiggestFish>
 */
class BiggestFishFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'odoo_partner_id' => fake()->unique()->numberBetween(1, 100000),
            'company_name' => 'Acme Ltd',
            'is_registered_company' => true,
            'address' => '1 High Street, London',
            'website' => 'https://acme.example',
            'logo_path' => null,
            'overview' => 'A large national manufacturer.',
            'company_size' => '2,000 employees',
            'estimated_value' => '£40 million',
            'estimated_value_gbp' => 40_000_000,
            'market_share' => 'National supplier',
            'total_spent' => '12500.00',
            'currency' => 'GBP',
            'status' => CustomerReviewStatus::Complete,
            'failure_reason' => null,
            'checked_at' => now(),
        ];
    }
}
