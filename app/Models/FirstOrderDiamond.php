<?php

namespace App\Models;

use App\Enums\FirstOrderDiamondStatus;
use App\Enums\OdooCompany;
use Database\Factories\FirstOrderDiamondFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $odoo_partner_id
 * @property int $odoo_order_id
 * @property string $order_name
 * @property array<int, array{quantity: string, name: string}>|null $order_lines
 * @property Carbon|null $ordered_at
 * @property string $company_name
 * @property bool $is_registered_company
 * @property string|null $address
 * @property string|null $website
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
    'order_lines',
    'ordered_at',
    'company_name',
    'is_registered_company',
    'address',
    'website',
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
        if ($this->logo_path === null || preg_match('/^first-order-diamonds\/\d+\.(png|jpe?g|gif|webp|ico)$/', $this->logo_path) !== 1) {
            return null;
        }

        return route('first-order-diamonds.logo', $this);
    }

    /**
     * Get a short label when no logo image is available.
     */
    public function logoInitial(): string
    {
        $letter = mb_substr(trim($this->company_name), 0, 1);

        return mb_strtoupper($letter !== '' ? $letter : '?');
    }

    /**
     * Keep only a normal web address that a person can open.
     */
    public static function httpUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return mb_substr($value, 0, 255);
    }

    /**
     * Get a safe link for the company website.
     */
    public function websiteUrl(): ?string
    {
        return self::httpUrl($this->website);
    }

    /**
     * Get the host shown for the company website.
     */
    public function websiteLabel(): ?string
    {
        $host = parse_url((string) $this->websiteUrl(), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Get the Odoo form for this sales order.
     */
    public function odooOrderUrl(): ?string
    {
        $baseUrl = once(fn (): ?string => OdooConnection::for(OdooCompany::MidMeds)?->url);

        if (! is_string($baseUrl) || $baseUrl === '') {
            return null;
        }

        return rtrim($baseUrl, '/').'/web#id='.$this->odoo_order_id.'&model=sale.order&view_type=form';
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
            'is_registered_company' => 'boolean',
            'order_lines' => 'array',
            'status' => FirstOrderDiamondStatus::class,
        ];
    }
}
