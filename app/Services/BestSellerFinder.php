<?php

namespace App\Services;

use App\Data\ShoppingProduct;
use App\Models\GoogleShoppingScan;

class BestSellerFinder
{
    public const int BATCH_SIZE = 20;

    /**
     * Find the next products down the best-seller list, ranked by units sold.
     * Once the list runs out the scan starts again from the best seller.
     *
     * @return array<int, ShoppingProduct>
     */
    public function nextProducts(OdooClient $client): array
    {
        $scan = GoogleShoppingScan::current();
        $groups = $this->bestSellers($client, $scan->next_rank);

        if ($groups === [] && $scan->next_rank > 0) {
            $scan->next_rank = 0;
            $groups = $this->bestSellers($client, 0);
        }

        $scan->next_rank += count($groups);
        $scan->save();

        $unitsSold = [];

        foreach ($groups as $group) {
            $productId = $this->relationId($group['product_id'] ?? null);
            $quantity = $group['product_uom_qty'] ?? $group['product_uom_qty_sum'] ?? null;

            if ($productId !== null) {
                $unitsSold[$productId] = is_numeric($quantity) ? (float) $quantity : 0.0;
            }
        }

        if ($unitsSold === []) {
            return [];
        }

        $rows = $client->read('product.product', array_keys($unitsSold), [
            'name',
            'default_code',
            'barcode',
            'lst_price',
            'standard_price',
        ]);
        $products = [];

        foreach ($rows as $row) {
            $id = $this->relationId($row['id'] ?? null);
            $name = $this->text($row['name'] ?? null);

            if ($id === null || $name === '' || ! isset($unitsSold[$id])) {
                continue;
            }

            $products[$id] = new ShoppingProduct(
                productId: $id,
                name: mb_substr($name, 0, 255),
                sku: mb_substr($this->text($row['default_code'] ?? null), 0, 64),
                barcode: mb_substr($this->text($row['barcode'] ?? null), 0, 64),
                unitsSold: $unitsSold[$id],
                salesPrice: $this->price($row['lst_price'] ?? null),
                costPrice: $this->price($row['standard_price'] ?? null),
            );
        }

        return array_values(array_filter(array_map(
            fn (int $id): ?ShoppingProduct => $products[$id] ?? null,
            array_keys($unitsSold),
        )));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function bestSellers(OdooClient $client, int $offset): array
    {
        return $client->readGroup(
            'sale.order.line',
            [
                ['order_id.state', 'in', ['sale', 'done']],
                ['product_id', '!=', false],
                ['product_id.sale_ok', '=', true],
                ['product_id.type', '!=', 'service'],
            ],
            ['product_uom_qty:sum'],
            ['product_id'],
            'product_uom_qty desc',
            self::BATCH_SIZE,
            $offset,
        );
    }

    private function price(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? round((float) $value, 2) : null;
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function relationId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_array($value) && is_int($value[0] ?? null)) {
            return $value[0];
        }

        return null;
    }
}
