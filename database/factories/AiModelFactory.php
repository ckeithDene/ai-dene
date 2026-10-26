<?php

namespace Database\Factories;

use App\Models\AiModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModel>
 */
class AiModelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'model' => 'gpt-4o',
            'organization' => null,
            'api_key' => 'test-api-key',
        ];
    }
}
