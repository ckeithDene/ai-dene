<?php

namespace App\Services;

use App\Data\CatalogueProduct;
use App\Models\FirstOrderDiamond;
use RuntimeException;

class DeneCatalogue
{
    /**
     * Read a sample of products and services sold by Dene Healthcare.
     *
     * @return array<int, CatalogueProduct>
     */
    public function sample(OdooClient $client): array
    {
        $products = [];

        try {
            $goods = $this->rows($client, [
                '&',
                ['sale_ok', '=', true],
                ['type', '!=', 'service'],
            ], $this->fields(), 40);
        } catch (RuntimeException) {
            $goods = $this->rows($client, [
                ['sale_ok', '=', true],
            ], ['name', 'default_code', 'list_price', 'categ_id'], 40);
        }

        foreach ($goods as $row) {
            $this->remember($products, $row);
        }

        try {
            $services = $this->rows($client, [
                '&',
                ['sale_ok', '=', true],
                ['type', '=', 'service'],
            ], $this->fields(), 20);
        } catch (RuntimeException) {
            $services = [];
        }

        foreach ($services as $row) {
            $this->remember($products, $row, true);
        }

        return array_values($products);
    }

    /**
     * Read product categories whose names match the supplied phrases.
     *
     * @param  array<int, string>  $terms
     * @return array<int, string>
     */
    public function categories(OdooClient $client, array $terms): array
    {
        return $this->categoryNames($client, function (string $term): array {
            return [['name', 'ilike', $term]];
        }, $terms);
    }

    /**
     * Read the first catalogue categories, used to confirm the connection.
     *
     * @return array<int, string>
     */
    public function categorySample(OdooClient $client): array
    {
        try {
            $rows = $client->searchRead('product.category', [], ['name', 'complete_name'], 'name asc', 15, 0);
        } catch (RuntimeException) {
            $rows = $client->searchRead('product.category', [], ['name'], 'name asc', 15, 0);
        }

        $names = [];

        foreach ($rows as $row) {
            $name = $this->text($row['complete_name'] ?? null);

            if ($name === '') {
                $name = $this->text($row['name'] ?? null);
            }

            if ($name !== '') {
                $names[$name] = $name;
            }
        }

        return array_values($names);
    }

    /**
     * @param  callable(string): array<int, mixed>  $domain
     * @param  array<int, string>  $terms
     * @return array<int, string>
     */
    private function categoryNames(OdooClient $client, callable $domain, array $terms): array
    {
        $names = [];

        foreach ($this->queries($terms) as $term) {
            try {
                $rows = $client->searchRead('product.category', $domain($term), ['name', 'complete_name'], 'name asc', 8, 0);
            } catch (RuntimeException) {
                $rows = $client->searchRead('product.category', $domain($term), ['name'], 'name asc', 8, 0);
            }

            foreach ($rows as $row) {
                $name = $this->text($row['complete_name'] ?? null);

                if ($name === '') {
                    $name = $this->text($row['name'] ?? null);
                }

                if ($name !== '') {
                    $names[$name] = $name;
                }
            }
        }

        return array_values($names);
    }

    /**
     * Search the Dene Healthcare catalogue for the supplied phrases.
     *
     * @param  array<int, string>  $terms
     * @return array<int, CatalogueProduct>
     */
    public function products(OdooClient $client, array $terms, bool $servicesOnly = false): array
    {
        $products = [];

        foreach ($this->queries($terms) as $term) {
            $domain = [
                '&',
                ['sale_ok', '=', true],
                '|',
                ['name', 'ilike', $term],
                ['default_code', 'ilike', $term],
            ];

            if ($servicesOnly) {
                $domain = ['&', ['type', '=', 'service'], ...$domain];
            }

            try {
                $rows = $this->rows($client, $domain, $this->fields(), 8);
            } catch (RuntimeException) {
                $rows = $this->rows($client, $domain, ['name', 'default_code', 'list_price', 'categ_id'], 8);
            }

            foreach ($rows as $row) {
                $this->remember($products, $row);

                if (count($products) >= 40) {
                    return array_values($products);
                }
            }
        }

        return array_values($products);
    }

    /**
     * @param  array<int, string>  $terms
     * @return array<int, string>
     */
    private function queries(array $terms): array
    {
        $queries = [];

        foreach ($terms as $term) {
            $term = trim($term);

            if (mb_strlen($term) >= 3) {
                $queries[strtolower($term)] = $term;
            }

            foreach (preg_split('/[^a-z0-9]+/i', strtolower($term)) ?: [] as $word) {
                if (mb_strlen($word) >= 4 && ! in_array($word, ['with', 'from', 'that', 'this', 'nhs', 'care', 'product', 'products', 'service', 'services', 'medical', 'clinical'], true)) {
                    $queries[$word] = $word;
                }
            }
        }

        return array_slice(array_values($queries), 0, 12);
    }

    /**
     * @return array<int, string>
     */
    private function fields(): array
    {
        return ['name', 'default_code', 'list_price', 'categ_id', 'type'];
    }

    /**
     * @param  array<int, mixed>  $domain
     * @param  array<int, string>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function rows(OdooClient $client, array $domain, array $fields, int $limit): array
    {
        $foundModel = false;

        foreach (['product.template', 'product.product'] as $model) {
            try {
                $rows = $client->searchRead($model, $domain, $fields, 'id asc', $limit, 0);
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
     * @param  array<int, CatalogueProduct>  $products
     * @param  array<string, mixed>  $row
     */
    private function remember(array &$products, array $row, bool $service = false): void
    {
        $product = $this->product($row, $service);

        if ($product !== null) {
            $products[$product->id] = $product;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function product(array $row, bool $service = false): ?CatalogueProduct
    {
        $id = $row['id'] ?? null;
        $name = $this->text($row['name'] ?? null);

        if (! is_numeric($id) || $name === '') {
            return null;
        }

        $id = (int) $id;

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
            service: $service || ($row['type'] ?? null) === 'service',
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
