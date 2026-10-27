<?php

namespace App\Models;

use App\Enums\CustomerReviewStatus;
use App\Enums\OdooCompany;
use Database\Factories\BiggestFishFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $odoo_partner_id
 * @property string $company_name
 * @property bool $is_registered_company
 * @property string|null $address
 * @property string|null $website
 * @property string|null $logo_path
 * @property string|null $overview
 * @property string|null $company_size
 * @property string|null $estimated_value
 * @property int|null $estimated_value_gbp
 * @property string|null $market_share
 * @property string $total_spent
 * @property string|null $currency
 * @property CustomerReviewStatus $status
 * @property string|null $failure_reason
 * @property Carbon|null $checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'odoo_partner_id',
    'company_name',
    'is_registered_company',
    'address',
    'website',
    'logo_path',
    'overview',
    'company_size',
    'estimated_value',
    'estimated_value_gbp',
    'market_share',
    'total_spent',
    'currency',
    'status',
    'failure_reason',
    'checked_at',
])]
class BiggestFish extends Model
{
    public const int VALUE_THRESHOLD_GBP = 20_000_000;

    /** @use HasFactory<BiggestFishFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'biggest_fish';

    /**
     * Get the public URL for the stored company logo.
     */
    public function logoUrl(): ?string
    {
        if ($this->logo_path === null || preg_match('/^biggest-fish\/\d+\.(png|jpe?g|gif|webp|ico)$/', $this->logo_path) !== 1) {
            return null;
        }

        return route('biggest-fish.logo', $this);
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
     * Get a safe link for the company website.
     */
    public function websiteUrl(): ?string
    {
        return FirstOrderDiamond::httpUrl($this->website);
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
     * Get the Odoo form for this customer.
     */
    public function odooPartnerUrl(): ?string
    {
        $baseUrl = once(fn (): ?string => OdooConnection::for(OdooCompany::MidMeds)?->url);

        if (! is_string($baseUrl) || $baseUrl === '') {
            return null;
        }

        return rtrim($baseUrl, '/').'/web#id='.$this->odoo_partner_id.'&model=res.partner&view_type=form';
    }

    /**
     * Get the estimated value, with a plain number shown in pounds.
     */
    public function estimatedValueLabel(): string
    {
        $value = trim((string) $this->estimated_value);

        if ($value === '') {
            return __('Unknown');
        }

        $amount = str_replace([',', ' ', '£', '€', '$'], '', $value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $amount) !== 1) {
            return $value;
        }

        $hasPence = preg_match('/\.\d*[1-9]/', $amount) === 1;

        return '£'.number_format((float) $amount, $hasPence ? 2 : 0);
    }

    /**
     * Get the confirmed-order total in a readable form.
     */
    public function totalSpentLabel(): string
    {
        $formatted = number_format((float) $this->total_spent, 2);

        if ($this->currency === null || $this->currency === 'GBP') {
            return '£'.$formatted;
        }

        return $formatted.' '.$this->currency;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_registered_company' => 'boolean',
            'estimated_value_gbp' => 'integer',
            'total_spent' => 'decimal:2',
            'checked_at' => 'datetime',
            'status' => CustomerReviewStatus::class,
        ];
    }
}
