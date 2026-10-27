<?php

namespace App\Models;

use App\Enums\GpDevelopmentType;
use App\Enums\GpGeography;
use App\Enums\GpLeadStatus;
use App\Enums\GpProjectStage;
use App\Enums\GpSalesPriority;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $fingerprint
 * @property string $surgery_name
 * @property string|null $website
 * @property string|null $town
 * @property string|null $county
 * @property GpGeography $nation
 * @property GpDevelopmentType $development_type
 * @property string|null $development_details
 * @property GpProjectStage $project_stage
 * @property int $stage_rank
 * @property GpLeadStatus $status
 * @property string|null $source_title
 * @property string|null $source_published_on
 * @property string|null $source_url
 * @property array<int, array<string, string>>|null $sources
 * @property string|null $project_dates
 * @property string|null $funding
 * @property string|null $planning_reference
 * @property string|null $planning_authority
 * @property string $source_confidence
 * @property string|null $fit_out_opportunity
 * @property string|null $sales_timing
 * @property GpSalesPriority $sales_priority
 * @property int $opportunity_score
 * @property string|null $next_action
 * @property string|null $summary
 * @property CarbonInterface|null $last_verified_at
 */
#[Fillable([
    'fingerprint',
    'surgery_name',
    'website',
    'town',
    'county',
    'nation',
    'development_type',
    'development_details',
    'project_stage',
    'stage_rank',
    'status',
    'source_title',
    'source_published_on',
    'source_url',
    'sources',
    'project_dates',
    'funding',
    'planning_reference',
    'planning_authority',
    'source_confidence',
    'fit_out_opportunity',
    'sales_timing',
    'sales_priority',
    'opportunity_score',
    'next_action',
    'summary',
    'last_verified_at',
])]
class GpPremisesLead extends Model
{
    /**
     * Get the town and county shown on a lead.
     */
    public function locationLabel(): string
    {
        $place = collect([$this->town, $this->county])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(', ');

        return $place !== '' ? $place.', '.$this->nation->label() : $this->nation->label();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nation' => GpGeography::class,
            'development_type' => GpDevelopmentType::class,
            'project_stage' => GpProjectStage::class,
            'status' => GpLeadStatus::class,
            'sales_priority' => GpSalesPriority::class,
            'sources' => 'array',
            'stage_rank' => 'integer',
            'opportunity_score' => 'integer',
            'last_verified_at' => 'datetime',
        ];
    }
}
