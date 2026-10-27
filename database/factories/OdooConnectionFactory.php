<?php

namespace Database\Factories;

use App\Enums\OdooCompany;
use App\Models\OdooConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OdooConnection>
 */
class OdooConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company' => OdooCompany::MidMeds,
            'url' => 'https://example.odoo.com',
            'database' => 'example',
            'username' => 'admin',
            'api_key' => 'test-api-key',
        ];
    }

    /**
     * Create the Dene Healthcare connection.
     */
    public function dene(): static
    {
        return $this->state(fn (): array => [
            'company' => OdooCompany::Dene,
        ]);
    }
}
