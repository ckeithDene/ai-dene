<?php

namespace App\Models;

use App\Enums\GpDevelopmentType;
use App\Enums\GpGeography;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property GpGeography $geography
 * @property int $source_window_months
 * @property int $future_horizon_months
 * @property array<int, string> $development_types
 * @property bool $high_priority_only
 * @property string|null $last_summary
 * @property string|null $last_limitations
 * @property CarbonInterface|null $last_ran_at
 */
#[Fillable([
    'geography',
    'source_window_months',
    'future_horizon_months',
    'development_types',
    'high_priority_only',
    'last_summary',
    'last_limitations',
    'last_ran_at',
])]
class GpPremisesSetting extends Model
{
    /**
     * Get the shared search settings, creating the defaults when none exist.
     */
    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([
            'geography' => GpGeography::UnitedKingdom,
            'source_window_months' => 12,
            'future_horizon_months' => 24,
            'development_types' => array_map(
                fn (GpDevelopmentType $type): string => $type->value,
                GpDevelopmentType::cases(),
            ),
            'high_priority_only' => false,
        ]);
    }

    /**
     * @return array<int, GpDevelopmentType>
     */
    public function selectedTypes(): array
    {
        $types = [];

        foreach ($this->development_types as $type) {
            $parsed = GpDevelopmentType::tryFrom($type);

            if ($parsed !== null) {
                $types[] = $parsed;
            }
        }

        return $types === [] ? GpDevelopmentType::cases() : $types;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPrompt(): array
    {
        return [
            'search_date' => now()->format('d/m/Y'),
            'geography' => $this->geography->label(),
            'sources_published_within_months' => $this->source_window_months,
            'future_projects_within_months' => $this->future_horizon_months,
            'development_types' => array_map(
                fn (GpDevelopmentType $type): string => $type->value,
                $this->selectedTypes(),
            ),
            'high_priority_only' => $this->high_priority_only,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geography' => GpGeography::class,
            'source_window_months' => 'integer',
            'future_horizon_months' => 'integer',
            'development_types' => 'array',
            'high_priority_only' => 'boolean',
            'last_ran_at' => 'datetime',
        ];
    }
}
