<?php

namespace App\Services;

use App\Enums\OdooCompany;
use App\Models\OdooConnection;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

class WarehouseOrders
{
    /**
     * List recent confirmed Dene Healthcare orders.
     *
     * @return array<int, array{id: int, name: string, customer: string, ordered_on: string}>
     */
    public function search(string $query): array
    {
        $domain = [
            ['state', 'in', ['sale', 'done']],
        ];

        if ($query !== '') {
            $domain = [
                '&',
                ['state', 'in', ['sale', 'done']],
                '|',
                ['name', 'ilike', $query],
                ['partner_id', 'ilike', $query],
            ];
        }

        $orders = [];

        foreach ($this->client()->searchRead('sale.order', $domain, ['id', 'name', 'partner_id', 'date_order'], 'date_order desc', 15, 0) as $order) {
            $id = $this->relationId($order['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $orders[] = [
                'id' => $id,
                'name' => $this->text($order['name'] ?? null, 'Order'),
                'customer' => $this->relationName($order['partner_id'] ?? null) ?? 'Unknown customer',
                'ordered_on' => $this->orderedOn($order['date_order'] ?? null),
            ];
        }

        return $orders;
    }

    /**
     * Build a picking run from the stock locations of one confirmed order.
     *
     * @return array{id: int, name: string, customer: string, ordered_on: string, picks: array<int, array{product: string, sku: string, quantity: float, location: string}>, missing: array<int, array{product: string, sku: string, quantity: float}>}
     */
    public function run(int $orderId): array
    {
        $client = $this->client();
        $orders = $client->searchRead('sale.order', [
            ['id', '=', $orderId],
            ['state', 'in', ['sale', 'done']],
        ], ['name', 'partner_id', 'date_order', 'order_line'], 'id asc', 1, 0);
        $order = $orders[0] ?? null;

        if (! is_array($order)) {
            throw new RuntimeException('That order was not found.');
        }

        $lineIds = [];

        foreach (is_array($order['order_line'] ?? null) ? $order['order_line'] : [] as $lineId) {
            if (is_int($lineId)) {
                $lineIds[] = $lineId;
            }
        }

        $lines = $lineIds === [] ? [] : $this->lines($client, $lineIds);
        $productIds = [];

        foreach ($lines as $line) {
            $productId = $this->relationId($line['product_id'] ?? null);

            if ($productId !== null) {
                $productIds[] = $productId;
            }
        }

        $products = $this->products($client, array_values(array_unique($productIds)));
        $locations = $this->locations($client, array_values(array_unique($productIds)));
        $picks = [];
        $missing = [];

        foreach ($lines as $line) {
            if (in_array($line['display_type'] ?? null, ['line_section', 'line_note'], true)) {
                continue;
            }

            $quantity = is_numeric($line['product_uom_qty'] ?? null) ? (float) $line['product_uom_qty'] : 0.0;

            if ($quantity <= 0) {
                continue;
            }

            $productId = $this->relationId($line['product_id'] ?? null);
            $product = $productId !== null ? ($products[$productId] ?? null) : null;
            $name = $product['name'] ?? $this->relationName($line['product_id'] ?? null) ?? $this->text($line['name'] ?? null, '');

            if ($name === '') {
                continue;
            }

            $row = [
                'product' => $name,
                'sku' => $product['sku'] ?? '',
                'quantity' => $quantity,
            ];
            $location = $productId !== null ? ($locations[$productId] ?? null) : null;

            if ($location === null) {
                $missing[] = $row;

                continue;
            }

            $picks[] = [
                ...$row,
                'location' => $location,
            ];
        }

        return [
            'id' => $orderId,
            'name' => $this->text($order['name'] ?? null, 'Order'),
            'customer' => $this->relationName($order['partner_id'] ?? null) ?? 'Unknown customer',
            'ordered_on' => $this->orderedOn($order['date_order'] ?? null),
            'picks' => $picks,
            'missing' => $missing,
        ];
    }

    private function client(): OdooClient
    {
        $connection = OdooConnection::for(OdooCompany::Dene);

        if ($connection === null || blank($connection->api_key)) {
            throw new RuntimeException('Connect the Dene Healthcare Odoo connection before loading orders. The MidMeds connection is not used here.');
        }

        return new OdooClient($connection);
    }

    /**
     * @param  array<int, int>  $lineIds
     * @return array<int, array<string, mixed>>
     */
    private function lines(OdooClient $client, array $lineIds): array
    {
        try {
            return $client->read('sale.order.line', $lineIds, ['name', 'product_uom_qty', 'product_id', 'display_type']);
        } catch (RuntimeException) {
            return $client->read('sale.order.line', $lineIds, ['name', 'product_uom_qty', 'product_id']);
        }
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array{name: string, sku: string}>
     */
    private function products(OdooClient $client, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        try {
            $rows = $client->read('product.product', $productIds, ['name', 'default_code']);
        } catch (RuntimeException) {
            return [];
        }

        $products = [];

        foreach ($rows as $row) {
            $id = $this->relationId($row['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $products[$id] = [
                'name' => $this->text($row['name'] ?? null, ''),
                'sku' => $this->text($row['default_code'] ?? null, ''),
            ];
        }

        return $products;
    }

    /**
     * Keep the fullest internal stock location for each product.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, string>
     */
    private function locations(OdooClient $client, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $domain = [
            ['product_id', 'in', $productIds],
            ['quantity', '>', 0],
        ];

        try {
            $rows = $client->searchRead('stock.quant', [
                ...$domain,
                ['location_id.usage', '=', 'internal'],
            ], ['product_id', 'location_id', 'quantity'], 'quantity desc', 500, 0);
        } catch (RuntimeException) {
            try {
                $rows = $client->searchRead('stock.quant', $domain, ['product_id', 'location_id', 'quantity'], 'quantity desc', 500, 0);
            } catch (RuntimeException) {
                return [];
            }
        }

        $locations = [];

        foreach ($rows as $row) {
            $productId = $this->relationId($row['product_id'] ?? null);
            $location = $this->relationName($row['location_id'] ?? null);

            if ($productId === null || $location === null || isset($locations[$productId])) {
                continue;
            }

            $locations[$productId] = $location;
        }

        return $locations;
    }

    private function orderedOn(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (Throwable) {
            return '';
        }
    }

    private function text(mixed $value, string $fallback): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $text = trim(explode("\n", str_replace("\r", '', $value))[0]);

        return $text === '' ? $fallback : mb_substr($text, 0, 255);
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

    private function relationName(mixed $value): ?string
    {
        if (! is_array($value) || ! is_string($value[1] ?? null)) {
            return null;
        }

        $name = trim($value[1]);

        return $name === '' ? null : mb_substr($name, 0, 255);
    }
}
