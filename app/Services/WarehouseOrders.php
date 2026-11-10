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

    /**
     * Read confirmed orders in a date range, with each product's stock location.
     *
     * @return array{from: string, to: string, order_count: int, truncated: bool, picks: array<int, array{order: string, product: string, sku: string, quantity: float, location: string, pharma: bool}>}
     */
    public function period(string $from, string $to): array
    {
        $start = $this->day($from)->startOfDay();
        $end = $this->day($to)->endOfDay();

        if ($end->lessThan($start)) {
            throw new RuntimeException('The end date must be on or after the start date.');
        }

        if ($start->copy()->addYear()->lessThan($end)) {
            throw new RuntimeException('Choose a date range of one year or less.');
        }

        set_time_limit(180);

        $client = $this->client();
        $page = $this->pages(function (int $limit, int $offset) use ($client, $start, $end): array {
            return $client->searchRead('sale.order', [
                ['state', 'in', ['sale', 'done']],
                ['date_order', '>=', $start->format('Y-m-d H:i:s')],
                ['date_order', '<=', $end->format('Y-m-d H:i:s')],
            ], ['id', 'name', 'date_order', 'order_line'], 'date_order asc', $limit, $offset);
        });
        $orders = $page['rows'];
        $lineIds = [];
        $orderNames = [];

        foreach ($orders as $order) {
            $id = $this->relationId($order['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $orderNames[$id] = $this->text($order['name'] ?? null, 'Order');

            foreach (is_array($order['order_line'] ?? null) ? $order['order_line'] : [] as $lineId) {
                if (is_int($lineId)) {
                    $lineIds[] = $lineId;
                }
            }
        }

        $lines = [];

        foreach (array_chunk($lineIds, 80) as $chunk) {
            array_push($lines, ...$this->lines($client, $chunk));
        }

        $productIds = [];

        foreach ($lines as $line) {
            $productId = $this->relationId($line['product_id'] ?? null);

            if ($productId !== null) {
                $productIds[] = $productId;
            }
        }

        $productIds = array_values(array_unique($productIds));
        $products = [];
        $locations = [];

        foreach (array_chunk($productIds, 80) as $chunk) {
            $products += $this->products($client, $chunk);
            $locations += $this->locations($client, $chunk);
        }

        $picks = [];

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
            $location = $productId !== null ? ($locations[$productId] ?? null) : null;

            if ($name === '' || $location === null) {
                continue;
            }

            $orderId = $this->relationId($line['order_id'] ?? null);

            $picks[] = [
                'order' => $orderNames[$orderId] ?? 'Order',
                'product' => $name,
                'sku' => $product['sku'] ?? '',
                'quantity' => $quantity,
                'location' => $location,
                'pharma' => $this->isPharma($location, $product['category'] ?? '', $name),
            ];
        }

        return [
            'from' => $start->format('d/m/Y'),
            'to' => $end->format('d/m/Y'),
            'order_count' => count($orderNames),
            'truncated' => $page['truncated'],
            'picks' => $picks,
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
        foreach ([
            ['name', 'product_uom_qty', 'product_id', 'display_type', 'order_id'],
            ['name', 'product_uom_qty', 'product_id', 'order_id'],
            ['name', 'product_uom_qty', 'product_id', 'display_type'],
            ['name', 'product_uom_qty', 'product_id'],
        ] as $fields) {
            try {
                return $client->read('sale.order.line', $lineIds, $fields);
            } catch (RuntimeException) {
                continue;
            }
        }

        throw new RuntimeException('Could not read Dene Healthcare orders from Odoo.');
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array{name: string, sku: string, category: string}>
     */
    private function products(OdooClient $client, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        try {
            $rows = $client->read('product.product', $productIds, ['name', 'default_code', 'categ_id']);
        } catch (RuntimeException) {
            try {
                $rows = $client->read('product.product', $productIds, ['name', 'default_code']);
            } catch (RuntimeException) {
                return [];
            }
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
                'category' => $this->relationName($row['categ_id'] ?? null) ?? '',
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
            $rows = $this->pages(fn (int $limit, int $offset): array => $client->searchRead('stock.quant', [
                ...$domain,
                ['location_id.usage', '=', 'internal'],
            ], ['product_id', 'location_id', 'quantity'], 'quantity desc', $limit, $offset))['rows'];
        } catch (RuntimeException) {
            try {
                $rows = $this->pages(fn (int $limit, int $offset): array => $client->searchRead('stock.quant', $domain, ['product_id', 'location_id', 'quantity'], 'quantity desc', $limit, $offset))['rows'];
            } catch (RuntimeException) {
                return [];
            }
        }

        $locations = [];

        foreach ($rows as $row) {
            $productId = $this->relationId($row['product_id'] ?? null);
            $location = $this->relationName($row['location_id'] ?? null);

            if ($productId === null || $location === null || isset($locations[$productId]) || $this->packingZone($location)) {
                continue;
            }

            $locations[$productId] = $location;
        }

        return $locations;
    }

    /**
     * Read every page of a search until the date range is exhausted.
     *
     * @param  callable(int, int): array<int, array<string, mixed>>  $read
     * @return array{rows: array<int, array<string, mixed>>, truncated: bool}
     */
    private function pages(callable $read): array
    {
        $rows = [];
        $offset = 0;
        $limit = 80;
        $truncated = false;

        do {
            $batch = $read($limit, $offset);
            array_push($rows, ...$batch);
            $offset += $limit;
            $truncated = count($batch) === $limit && $offset >= 20000;
        } while (count($batch) === $limit && $offset < 20000);

        return [
            'rows' => $rows,
            'truncated' => $truncated,
        ];
    }

    private function packingZone(string $location): bool
    {
        return str_contains(strtoupper($location), 'PACKING');
    }

    private function day(string $value): Carbon
    {
        try {
            $day = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            throw new RuntimeException('Choose a start and end date.');
        }

        if (! $day instanceof Carbon || $day->format('Y-m-d') !== $value) {
            throw new RuntimeException('Choose a start and end date.');
        }

        return $day;
    }

    private function isPharma(string $location, string $category, string $name): bool
    {
        $code = strtoupper(trim(str_contains($location, '/') ? substr($location, (int) strrpos($location, '/') + 1) : $location));
        $text = strtolower($category.' '.$name);

        return str_starts_with($code, '7L:')
            || preg_match('/\b(pharma|pharmaceutical|drug|medicine|vaccine)\b/', $text) === 1;
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
