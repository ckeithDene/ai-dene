<?php

namespace App\Models;

use App\Enums\CustomerReviewStatus;
use App\Enums\OdooCompany;
use Database\Factories\GoogleShoppingSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $odoo_product_id
 * @property string $product_name
 * @property string|null $sku
 * @property string|null $barcode
 * @property string $units_sold
 * @property string|null $sales_price
 * @property string|null $cost_price
 * @property string|null $cheapest_price
 * @property string|null $cheapest_seller
 * @property string|null $cheapest_url
 * @property string|null $matched_product
 * @property string|null $notes
 * @property bool $beats_sales_price
 * @property bool $beats_cost_price
 * @property string|null $potential_margin
 * @property string|null $potential_margin_percent
 * @property CustomerReviewStatus $status
 * @property string|null $failure_reason
 * @property Carbon|null $checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'odoo_product_id',
    'product_name',
    'sku',
    'barcode',
    'units_sold',
    'sales_price',
    'cost_price',
    'cheapest_price',
    'cheapest_seller',
    'cheapest_url',
    'matched_product',
    'notes',
    'beats_sales_price',
    'beats_cost_price',
    'potential_margin',
    'potential_margin_percent',
    'status',
    'failure_reason',
    'checked_at',
])]
class GoogleShoppingSuggestion extends Model
{
    /**
     * Odoo prices exclude VAT while Google Shopping prices include it.
     */
    public const float VAT_RATE = 0.2;

    /**
     * How far below the cheapest Google Shopping price we would list to win.
     */
    public const float UNDERCUT = 0.01;

    /** @use HasFactory<GoogleShoppingSuggestionFactory> */
    use HasFactory;

    /**
     * Work out whether our sales or cost price beats the cheapest Google Shopping price.
     */
    public function applyCheapestPrice(?float $cheapestPrice): void
    {
        $this->cheapest_price = $cheapestPrice !== null ? number_format($cheapestPrice, 2, '.', '') : null;
        $this->beats_sales_price = false;
        $this->beats_cost_price = false;
        $this->potential_margin = null;
        $this->potential_margin_percent = null;

        if ($cheapestPrice === null || $cheapestPrice <= self::UNDERCUT) {
            return;
        }

        $salesPrice = $this->salesPriceIncVat();

        $this->beats_sales_price = $salesPrice !== null && $salesPrice < $cheapestPrice;

        $cost = $this->cost_price !== null ? (float) $this->cost_price : 0.0;

        if ($cost <= 0) {
            return;
        }

        $targetExVat = $this->targetPriceExVat($cheapestPrice);
        $margin = $targetExVat - $cost;

        $this->beats_cost_price = $margin > 0;
        $this->potential_margin = number_format($margin, 2, '.', '');
        $this->potential_margin_percent = number_format(max(-9999.99, $margin / $targetExVat * 100), 2, '.', '');
    }

    public function salesPriceIncVat(): ?float
    {
        if ($this->sales_price === null || (float) $this->sales_price <= 0) {
            return null;
        }

        return round((float) $this->sales_price * (1 + self::VAT_RATE), 2);
    }

    public function costPriceIncVat(): ?float
    {
        if ($this->cost_price === null || (float) $this->cost_price <= 0) {
            return null;
        }

        return round((float) $this->cost_price * (1 + self::VAT_RATE), 2);
    }

    /**
     * Get the price, including VAT, we would list at to beat the cheapest seller.
     */
    public function targetPriceIncVat(): ?float
    {
        if ($this->cheapest_price === null) {
            return null;
        }

        return round((float) $this->cheapest_price - self::UNDERCUT, 2);
    }

    /**
     * Get how much cheaper our current sales price is, including VAT.
     */
    public function salesSaving(): ?float
    {
        $salesPrice = $this->salesPriceIncVat();

        if ($salesPrice === null || $this->cheapest_price === null) {
            return null;
        }

        return round((float) $this->cheapest_price - $salesPrice, 2);
    }

    /**
     * Get a safe link to the cheapest Google Shopping listing.
     */
    public function cheapestUrl(): ?string
    {
        return FirstOrderDiamond::httpUrl($this->cheapest_url);
    }

    /**
     * Get the Odoo form for this product.
     */
    public function odooProductUrl(): ?string
    {
        $baseUrl = once(fn (): ?string => OdooConnection::for(OdooCompany::MidMeds)?->url);

        if (! is_string($baseUrl) || $baseUrl === '') {
            return null;
        }

        return rtrim($baseUrl, '/').'/web#id='.$this->odoo_product_id.'&model=product.product&view_type=form';
    }

    public static function money(float|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return __('Unknown');
        }

        return '£'.number_format((float) $amount, 2);
    }

    public function unitsSoldLabel(): string
    {
        return rtrim(rtrim(number_format((float) $this->units_sold, 2), '0'), '.');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units_sold' => 'decimal:2',
            'sales_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'cheapest_price' => 'decimal:2',
            'beats_sales_price' => 'boolean',
            'beats_cost_price' => 'boolean',
            'potential_margin' => 'decimal:2',
            'potential_margin_percent' => 'decimal:2',
            'checked_at' => 'datetime',
            'status' => CustomerReviewStatus::class,
        ];
    }

    private function targetPriceExVat(float $cheapestPrice): float
    {
        return ($cheapestPrice - self::UNDERCUT) / (1 + self::VAT_RATE);
    }
}
