<?php

namespace App\Models;

use App\Enums\ResearchDepth;
use App\Enums\SalesObjective;
use App\Enums\SalesTone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property SalesObjective $objective
 * @property SalesTone $tone
 * @property ResearchDepth $research_depth
 * @property bool $suggest_account_pricing
 * @property bool $promote_repeat_ordering
 * @property bool $evaluate_pharmaceuticals
 * @property int $max_products
 */
#[Fillable([
    'objective',
    'tone',
    'research_depth',
    'suggest_account_pricing',
    'promote_repeat_ordering',
    'evaluate_pharmaceuticals',
    'max_products',
])]
class SalesEngineSetting extends Model
{
    /**
     * Get the shared sales-engine settings, creating the defaults when none exist.
     */
    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([
            'objective' => SalesObjective::Balanced,
            'tone' => SalesTone::AiSelects,
            'research_depth' => ResearchDepth::Comprehensive,
            'suggest_account_pricing' => true,
            'promote_repeat_ordering' => true,
            'evaluate_pharmaceuticals' => true,
            'max_products' => 8,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPrompt(): array
    {
        return [
            'objective' => $this->objective->value,
            'tone' => $this->tone->value,
            'suggest_account_pricing' => $this->suggest_account_pricing,
            'promote_repeat_ordering' => $this->promote_repeat_ordering,
            'evaluate_pharmaceuticals' => $this->evaluate_pharmaceuticals,
            'max_products' => $this->max_products,
            'research_depth' => $this->research_depth->value,
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
            'objective' => SalesObjective::class,
            'tone' => SalesTone::class,
            'research_depth' => ResearchDepth::class,
            'suggest_account_pricing' => 'boolean',
            'promote_repeat_ordering' => 'boolean',
            'evaluate_pharmaceuticals' => 'boolean',
            'max_products' => 'integer',
        ];
    }
}
