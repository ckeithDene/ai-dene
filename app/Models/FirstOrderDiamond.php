<?php

namespace App\Models;

use App\Enums\FirstOrderDiamondStatus;
use Database\Factories\FirstOrderDiamondFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $odoo_partner_id
 * @property int $odoo_order_id
 * @property string $order_name
 * @property Carbon|null $ordered_at
 * @property string $company_name
 * @property string|null $address
 * @property string|null $logo_path
 * @property string|null $overview
 * @property string|null $company_size
 * @property string|null $estimated_value
 * @property string|null $market_share
 * @property FirstOrderDiamondStatus $status
 * @property string|null $failure_reason
 * @property Carbon|null $checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'odoo_partner_id',
    'odoo_order_id',
    'order_name',
    'ordered_at',
    'company_name',
    'address',
    'logo_path',
    'overview',
    'company_size',
    'estimated_value',
    'market_share',
    'status',
    'failure_reason',
    'checked_at',
])]
class FirstOrderDiamond extends Model
{
    /** @use HasFactory<FirstOrderDiamondFactory> */
    use HasFactory;

    /**
     * Get the public URL for the stored company logo.
     */
    public function logoUrl(): ?string
    {
        if ($this->logo_path === null) {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'checked_at' => 'datetime',
            'status' => FirstOrderDiamondStatus::class,
        ];
    }
}
