<?php

namespace App\Services;

use App\Data\CatalogueProduct;
use App\Models\FirstOrderDiamond;
use RuntimeException;

class DeneCatalogue
{
    /**
     * Search the Dene Healthcare catalogue for the supplied phrases.
     *
     * @param  array<int, string>  $terms
     * @return array<int, CatalogueProduct>
     */
    public function products(OdooClient $client, array $terms): array
    {
        $products = [];

        foreach (array_slice(array_values(array_unique($terms)), 0, 8) as $term) {
            foreach ($this->search($client, $term) as $row) {
                $product = $this->product($row);

                if ($product === null || isset($products[$product->id])) {
                    continue;
                }

                $products[$product->id] = $product;

                if (count($products) >= 24) {
                    return array_values($products);
                }
            }
        }

        return array_values($products);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function search(OdooClient $client, string $term): array
    {
        $foundModel = false;

        foreach (['product.template', 'product.product'] as $model) {
            try {
                $rows = $client->searchRead($model, [
                    '&',
                    ['sale_ok', '=', true],
                    '|',
                    ['name', 'ilike', $term],
                    ['default_code', 'ilike', $term],
                ], ['name', 'default_code', 'list_price', 'categ_id'], 'id asc', 6, 0);
                $foundModel = true;
            } catch (RuntimeException) {
                continue;
            }

            if ($rows !== []) {
                return $rows;
            }
        }

        if (! $foundModel) {
            throw new RuntimeException('Could not read the Dene Healthcare catalogue from Odoo.');
        }

        return [];
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

        $category = is_array($row['categ_id'] ?? null) && is_string($row['categ_id'][1] ?? null)
            ? trim($row['categ_id'][1])
            : '';
        $price = $row['list_price'] ?? null;

        return new CatalogueProduct(
            id: $id,
            name: mb_substr($name, 0, 180),
            sku: mb_substr($this->text($row['default_code'] ?? null), 0, 64),
            category: mb_substr($category, 0, 120),
            listPrice: is_numeric($price) ? '£'.number_format((float) $price, 2) : null,
            url: FirstOrderDiamond::httpUrl(is_string($row['website_url'] ?? null) ? $row['website_url'] : null),
            available: null,
            description: '',
            pharmaceutical: false,
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
}
