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
     * Read the confirmed-order total for this customer.
     */
    public function confirmedSpend(OdooClient $client, int $partnerId): ?string
    {
        $groups = $client->readGroup(
            'sale.order',
            [
                ['state', 'in', ['sale', 'done']],
                ['partner_id.commercial_partner_id', '=', $partnerId],
            ],
            ['amount_total:sum'],
            ['currency_id'],
        );
        $totals = [];

        foreach ($groups as $group) {
            $amount = $group['amount_total'] ?? $group['amount_total_sum'] ?? null;

            if (! is_numeric($amount)) {
                continue;
            }

            $currency = $this->many2oneName($group['currency_id'] ?? null);
            $currency = $currency !== '' ? $currency : 'GBP';
            $totals[$currency] = ($totals[$currency] ?? 0) + (float) $amount;
        }

        if ($totals === []) {
            return null;
        }

        arsort($totals);
        $currency = (string) array_key_first($totals);
        $formatted = number_format($totals[$currency], 2);

        return $currency === 'GBP' ? '£'.$formatted : $formatted.' '.$currency;
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
            ], ['name', 'product_id', 'product_uom_qty', 'price_subtotal'], 'id desc', 40, 0);
        } catch (RuntimeException) {
            return [];
        }

        $purchases = [];

        foreach ($rows as $row) {
            $name = $this->many2oneName($row['product_id'] ?? null);
            $name = $name !== '' ? $name : $this->text($row['name'] ?? null);
            $key = $this->productLineKey($name);

            if ($key === '') {
                continue;
            }

            $quantity = is_numeric($row['product_uom_qty'] ?? null) ? (float) $row['product_uom_qty'] : 0;
            $spent = is_numeric($row['price_subtotal'] ?? null) ? (float) $row['price_subtotal'] : 0;

            if (! isset($purchases[$key])) {
                $purchases[$key] = [
                    'name' => mb_substr(trim(strip_tags($name)), 0, 180),
                    'quantity' => 0.0,
                    'spent' => 0.0,
                ];
            }

            $purchases[$key]['quantity'] += $quantity;
            $purchases[$key]['spent'] += $spent;
        }

        return array_map(fn (array $purchase): array => [
            'name' => $purchase['name'],
            'quantity' => rtrim(rtrim(number_format($purchase['quantity'], 2), '0'), '.'),
            'spent' => '£'.number_format($purchase['spent'], 2),
        ], array_values($purchases));
    }

    /**
     * Search the sellable catalogue for other product lines.
     *
     * @param  array<int, string>  $terms
     * @param  array<int, string>  $categories
     * @param  array<int, string>  $excludeNames
     * @return array<int, CatalogueProduct>
     */
    public function products(OdooClient $client, array $terms, array $categories, array $excludeNames, int $limit, bool $includePharmaceuticals): array
    {
        $products = [];
        $queries = array_values(array_unique(array_merge(array_slice($terms, 0, 8), array_slice($categories, 0, 8))));

        foreach ($queries as $term) {
            $added = 0;

            foreach ($this->search($client, $term, 8) as $row) {
                $product = $this->product($row);

                if ($product === null || isset($products[$product->id]) || (! $includePharmaceuticals && $product->pharmaceutical)) {
                    continue;
                }

                if ($this->matchesAny($product->name, $excludeNames)) {
                    continue;
                }

                $products[$product->id] = $product;
                $added++;

                if ($added === 2 || count($products) >= $limit) {
                    break;
                }
            }

            if (count($products) >= $limit) {
                break;
            }
        }

        return array_values($products);
    }

    /**
     * Decide whether two catalogue names are the same product line.
     */
    public function sameProductLine(string $left, string $right): bool
    {
        $left = $this->productLineKey($left);
        $right = $this->productLineKey($right);

        if ($left === '' || $right === '' || strlen($left) < 4 || strlen($right) < 4) {
            return false;
        }

        return $left === $right || str_contains($left, $right) || str_contains($right, $left);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @return array<int, array<string, mixed>>
     */
    private function search(OdooClient $client, string $term, int $limit): array
    {
        $foundModel = false;

        foreach (['product.template', 'product.product'] as $model) {
            try {
                $rows = $client->searchRead($model, [
                    '&',
                    ['sale_ok', '=', true],
                    '|',
                    ['name', 'ilike', $term],
                    '|',
                    ['default_code', 'ilike', $term],
                    ['categ_id.name', 'ilike', $term],
                ], ['name', 'default_code', 'list_price', 'categ_id', 'description_sale', 'website_url'], 'id asc', $limit, 0);
                $foundModel = true;
            } catch (RuntimeException) {
                try {
                    $rows = $client->searchRead($model, [
                        '&',
                        ['sale_ok', '=', true],
                        '|',
                        ['name', 'ilike', $term],
                        ['default_code', 'ilike', $term],
                    ], ['name', 'default_code', 'list_price', 'categ_id'], 'id asc', $limit, 0);
                    $foundModel = true;
                } catch (RuntimeException) {
                    continue;
                }
            }

            if ($rows !== []) {
                return $rows;
            }
        }

        if (! $foundModel) {
            throw new RuntimeException('Could not read the MidMeds catalogue from Odoo.');
        }

        return [];
    }

    /**
     * @param  array<int, string>  $names
     */
    private function matchesAny(string $name, array $names): bool
    {
        foreach ($names as $excluded) {
            if ($this->sameProductLine($name, $excluded)) {
                return true;
            }
        }

        return false;
    }

    private function productLineKey(string $name): string
    {
        $name = strtolower(trim(strip_tags($name)));
        $name = preg_replace('/\([^)]*\)/', ' ', $name) ?? $name;
        $name = preg_replace('/\b(\d+([.,]\d+)?\s*(mm|cm|ml|mg|g|kg|pk|pack|packs|box|boxes|pcs|pc|x)?|xxs|xs|small|medium|large|xl|xxl|xxxl)\b/', ' ', $name) ?? $name;
        $name = preg_replace('/[^a-z]+/', ' ', $name) ?? $name;
        $words = array_values(array_filter(explode(' ', trim($name))));

        return implode(' ', array_slice($words, 0, 4));
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
