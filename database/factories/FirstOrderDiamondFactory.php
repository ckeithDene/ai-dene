<?php

namespace Database\Factories;

use App\Enums\FirstOrderDiamondStatus;
use App\Models\FirstOrderDiamond;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirstOrderDiamond>
 */
class FirstOrderDiamondFactory extends Factory
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
            'odoo_order_id' => fake()->unique()->numberBetween(1, 100000),
            'order_name' => 'S00010',
            'ordered_at' => '2026-10-01 09:00:00',
            'company_name' => 'Acme Ltd',
            'address' => '1 High Street, London',
            'logo_path' => null,
            'overview' => 'A strong first-order lead.',
            'company_size' => '200 employees',
            'estimated_value' => '£40 million',
            'market_share' => 'Small regional share',
            'status' => FirstOrderDiamondStatus::Complete,
            'failure_reason' => null,
            'checked_at' => now(),
        ];
    }
}
