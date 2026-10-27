<?php

namespace App\Models;

use App\Enums\NhsCoverage;
use App\Enums\NhsGapType;
use App\Enums\NhsSourcingAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $nhs_product_opportunity_id
 * @property NhsGapType $gap_type
 * @property NhsCoverage $coverage
 * @property NhsSourcingAction $sourcing_action
 * @property int|null $gap_score
 * @property string|null $recommendation
 * @property string|null $missing_items
 * @property array<int, array<string, mixed>>|null $matched_products
 */
#[Fillable([
    'nhs_product_opportunity_id',
    'gap_type',
    'coverage',
    'sourcing_action',
    'gap_score',
    'recommendation',
    'missing_items',
    'matched_products',
])]
class NhsCatalogueGap extends Model
{
    /**
     * The product opportunity this gap belongs to.
     *
     * @return BelongsTo<NhsProductOpportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(NhsProductOpportunity::class, 'nhs_product_opportunity_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gap_type' => NhsGapType::class,
            'coverage' => NhsCoverage::class,
            'sourcing_action' => NhsSourcingAction::class,
            'gap_score' => 'integer',
            'matched_products' => 'array',
        ];
    }
}
