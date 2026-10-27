<?php

namespace App\Services;

use App\Data\CatalogueProduct;
use App\Models\FirstOrderDiamond;
use RuntimeException;

class MidMedsCatalogue
{
    /**
     * Read product category names from Odoo.
     *
     * @return array<int, string>
     */
    public function categories(OdooClient $client): array
    {
        $rows = $client->searchRead('product.category', [], ['name'], 'name asc', 80, 0);
        $names = [];

        foreach ($rows as $row) {
            $name = $this->text($row['name'] ?? null);

            if ($name === '') {
                continue;
            }

            $names[] = mb_substr($name, 0, 80);
        }

        return $names;
    }

    /**
     * Read confirmed products this customer has already bought.
     *
     * @return array<int, array{name: string, quantity: string, spent: string}>
     */
    public function purchaseHistory(OdooClient $client, int $partnerId): array
    {
        try {
            $rows = $client->searchRead('sale.order.line', [
                '&',
                ['order_id.state', 'in', ['sale', 'done']],
                ['order_id.partner_id', 'child_of', $partnerId],
            ], ['name', 'product_uom_qty', 'price_subtotal'], 'id desc', 20, 0);
        } catch (RuntimeException) {
            return [];
        }

        $purchases = [];

        foreach ($rows as $row) {
            $name = $this->text($row['name'] ?? null);

            if ($name === '') {
                continue;
            }

            $quantity = $row['product_uom_qty'] ?? 1;
            $spent = $row['price_subtotal'] ?? null;
            $purchases[] = [
                'name' => mb_substr(trim(strip_tags($name)), 0, 180),
                'quantity' => is_numeric($quantity) ? (string) $quantity : '1',
                'spent' => is_numeric($spent) ? '£'.number_format((float) $spent, 2) : '',
            ];
        }

        return $purchases;
    }

    /**
     * Search the sellable catalogue for the research terms.
     *
     * @param  array<int, string>  $terms
     * @return array<int, CatalogueProduct>
     */
    public function products(OdooClient $client, array $terms, int $limit, bool $includePharmaceuticals): array
    {
        $products = [];

        foreach (array_slice($terms, 0, 8) as $term) {
            foreach ($this->search($client, $term, min(12, $limit)) as $row) {
                $product = $this->product($row);

                if ($product === null || (! $includePharmaceuticals && $product->pharmaceutical)) {
                    continue;
                }

                $products[$product->id] = $product;

                if (count($products) >= $limit) {
                    return array_values($products);
                }
            }
        }

        return array_values($products);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function search(OdooClient $client, string $term, int $limit): array
    {
        try {
            return $client->searchRead('product.product', [
                '&',
                ['sale_ok', '=', true],
                '|',
                ['name', 'ilike', $term],
                '|',
                ['default_code', 'ilike', $term],
                ['categ_id.name', 'ilike', $term],
            ], ['name', 'default_code', 'list_price', 'categ_id', 'qty_available', 'description_sale', 'website_url'], 'id asc', $limit, 0);
        } catch (RuntimeException) {
            return $client->searchRead('product.product', [
                '&',
                ['sale_ok', '=', true],
                '|',
                ['name', 'ilike', $term],
                ['default_code', 'ilike', $term],
            ], ['name', 'default_code', 'list_price', 'categ_id'], 'id asc', $limit, 0);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function product(array $row): ?CatalogueProduct
    {
        $id = $row['id'] ?? null;
        $name = $this->text($row['name'] ?? null);

        if (! is_int($id) || $name === '') {
            return null;
        }

        $category = $this->many2oneName($row['categ_id'] ?? null);
        $price = $row['list_price'] ?? null;
        $available = $row['qty_available'] ?? null;

        return new CatalogueProduct(
            id: $id,
            name: mb_substr($name, 0, 180),
            sku: mb_substr($this->text($row['default_code'] ?? null), 0, 64),
            category: mb_substr($category, 0, 120),
            listPrice: is_numeric($price) ? '£'.number_format((float) $price, 2) : null,
            url: FirstOrderDiamond::httpUrl(is_string($row['website_url'] ?? null) ? $row['website_url'] : null),
            available: is_numeric($available) ? (float) $available : null,
            description: mb_substr(trim(strip_tags($this->text($row['description_sale'] ?? null))), 0, 300),
            pharmaceutical: preg_match('/pharmaceutical|medicine|vaccine|prescription/i', $name.' '.$category) === 1,
        );
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        $text = trim((string) $value);

        return strtolower($text) === 'false' ? '' : $text;
    }

    private function many2oneName(mixed $value): string
    {
        if (! is_array($value) || ! is_string($value[1] ?? null)) {
            return '';
        }

        return trim($value[1]);
    }
}
