<?php

namespace App\Actions;

use App\Data\DiamondCheckResult;
use App\Data\ShoppingProduct;
use App\Enums\CustomerReviewStatus;
use App\Enums\OdooCompany;
use App\Models\AiModel;
use App\Models\GoogleShoppingSuggestion;
use App\Models\OdooConnection;
use App\Services\BestSellerFinder;
use App\Services\GoogleShoppingResearcher;
use App\Services\OdooClient;
use RuntimeException;
use Throwable;

class CheckGoogleShoppingPrices
{
    /**
     * Price the next batch of best sellers against Google Shopping.
     */
    public function handle(): DiamondCheckResult
    {
        set_time_limit(0);

        $connection = OdooConnection::for(OdooCompany::MidMeds);
        $aiModel = AiModel::current();

        if ($connection === null || blank($connection->api_key)) {
            return new DiamondCheckResult(0, __('Connect Odoo before checking Google Shopping prices.'));
        }

        if ($aiModel === null || blank($aiModel->api_key)) {
            return new DiamondCheckResult(0, __('Connect ChatGPT before checking Google Shopping prices.'));
        }

        try {
            $products = (new BestSellerFinder)->nextProducts(new OdooClient($connection));
        } catch (RuntimeException) {
            return new DiamondCheckResult(0, __('Could not read best-selling products from Odoo.'));
        }

        foreach ($products as $product) {
            $this->check($product, $aiModel);
        }

        return new DiamondCheckResult(count($products), null);
    }

    /**
     * Save one product's prices, then compare them with the cheapest Google Shopping price.
     */
    private function check(ShoppingProduct $product, AiModel $aiModel): void
    {
        $suggestion = GoogleShoppingSuggestion::query()->firstOrNew([
            'odoo_product_id' => $product->productId,
        ]);

        $suggestion->fill([
            'product_name' => $product->name,
            'sku' => $product->sku !== '' ? $product->sku : null,
            'barcode' => $product->barcode !== '' ? $product->barcode : null,
            'units_sold' => number_format($product->unitsSold, 2, '.', ''),
            'sales_price' => $product->salesPrice,
            'cost_price' => $product->costPrice,
            'status' => CustomerReviewStatus::Pending,
            'failure_reason' => null,
        ]);
        $suggestion->save();

        try {
            $price = (new GoogleShoppingResearcher)->cheapestPrice($aiModel, $product);

            $suggestion->fill([
                'cheapest_seller' => $price->seller,
                'cheapest_url' => $price->url,
                'matched_product' => $price->matchedProduct,
                'notes' => $price->notes,
                'status' => CustomerReviewStatus::Complete,
                'checked_at' => now(),
            ]);
            $suggestion->applyCheapestPrice($price->cheapestPrice);
            $suggestion->save();
        } catch (Throwable) {
            $suggestion->fill([
                'status' => CustomerReviewStatus::Failed,
                'failure_reason' => __('ChatGPT could not price this product.'),
                'checked_at' => now(),
            ]);
            $suggestion->save();
        }
    }
}
