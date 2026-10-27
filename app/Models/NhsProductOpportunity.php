<?php

namespace App\Models;

use App\Enums\NhsDemandDirection;
use App\Enums\NhsTimeframe;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $fingerprint
 * @property string $name
 * @property string|null $category
 * @property NhsDemandDirection $demand_direction
 * @property NhsTimeframe|null $timeframe
 * @property string|null $explanation
 * @property string|null $commercial_action
 * @property string|null $secondary_demand
 * @property string|null $sales_proposition
 * @property int $opportunity_score
 * @property string $confidence
 * @property array<int, string>|null $search_terms
 * @property bool $requirement_confirmed
 * @property CarbonInterface|null $last_verified_at
 */
#[Fillable([
    'fingerprint',
    'name',
    'category',
    'demand_direction',
    'timeframe',
    'explanation',
    'commercial_action',
    'secondary_demand',
    'sales_proposition',
    'opportunity_score',
    'confidence',
    'search_terms',
    'requirement_confirmed',
    'last_verified_at',
])]
class NhsProductOpportunity extends Model
{
    /**
     * Directives that support this product opportunity.
     *
     * @return BelongsToMany<NhsDirective, $this>
     */
    public function directives(): BelongsToMany
    {
        return $this->belongsToMany(NhsDirective::class, 'nhs_directive_opportunity');
    }

    /**
     * The catalogue comparison for this opportunity.
     *
     * @return HasOne<NhsCatalogueGap, $this>
     */
    public function gap(): HasOne
    {
        return $this->hasOne(NhsCatalogueGap::class);
    }

    public function priorityLabel(): string
    {
        return match (true) {
            $this->opportunity_score >= 80 => __('Critical'),
            $this->opportunity_score >= 60 => __('High priority'),
            $this->opportunity_score >= 40 => __('Monitor'),
            default => __('Low priority'),
        };
    }

    public function directionLabel(): string
    {
        if ($this->demand_direction->isGrowth()) {
            return __('Growth');
        }

        if ($this->demand_direction->isDecline()) {
            return __('Decline');
        }

        return $this->demand_direction->label();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'demand_direction' => NhsDemandDirection::class,
            'timeframe' => NhsTimeframe::class,
            'search_terms' => 'array',
            'opportunity_score' => 'integer',
            'requirement_confirmed' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }
}
