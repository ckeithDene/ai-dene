<?php

namespace Database\Factories;

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
            'url' => 'https://example.odoo.com',
            'database' => 'example',
            'username' => 'admin',
            'api_key' => 'test-api-key',
        ];
    }
}
