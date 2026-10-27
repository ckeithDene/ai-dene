<?php

namespace App\Models;

use App\Enums\SalesStrategyStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $odoo_partner_id
 * @property string $company_name
 * @property SalesStrategyStatus $status
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $research
 * @property array<string, mixed>|null $report
 * @property CarbonInterface|null $generated_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'odoo_partner_id',
    'company_name',
    'status',
    'failure_reason',
    'research',
    'report',
    'generated_at',
])]
class SalesStrategy extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'research' => 'array',
            'report' => 'array',
            'generated_at' => 'datetime',
            'status' => SalesStrategyStatus::class,
        ];
    }
}
