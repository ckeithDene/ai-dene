<?php

namespace App\Services;

class WarehouseMovePlanner
{
    /**
     * Suggest one joint rearrangement.
     *
     * Each suggestion is chosen against the locations already assigned in this run. Pharma and drug-room stock stay where they are. The packing zone is not a stock location. A product that loses its bin is placed in a location this same plan leaves empty.
     *
     * @param  array<int, array{product: string, sku: string, quantity: float, location: string, pharma: bool}>  $picks
     * @return array{suggestions: array<int, array<string, mixed>>, pharma_left: int}
     */
    public function suggest(array $picks): array
    {
        $collected = $this->collect($picks);
        $pool = $this->pool($collected['products']);
        $ranked = $pool;
        usort($ranked, fn (array $left, array $right): int => $right['demand'] <=> $left['demand'] ?: $left['seconds'] <=> $right['seconds'] ?: $left['code'] <=> $right['code']);
        $slots = $pool;
        usort($slots, fn (array $left, array $right): int => $left['seconds'] <=> $right['seconds'] ?: $left['code'] <=> $right['code']);
        $targets = [];

        foreach ($ranked as $index => $product) {
            $targets[$product['key']] = $slots[$index];
        }

        return [
            'suggestions' => $this->describe($pool, $targets, 15),
            'pharma_left' => $collected['pharma_left'],
        ];
    }

    /**
     * Accept a rearrangement only when it uses known stock locations and leaves every displaced product in a bin this plan frees.
     *
     * @param  array<int, array{product: string, sku: string, quantity: float, location: string, pharma: bool}>  $picks
     * @param  array<int, array{product: string, sku: string, from: string, to: string}>  $proposed
     * @return array<int, array<string, mixed>>|null
     */
    public function proposals(array $picks, array $proposed): ?array
    {
        if ($proposed === []) {
            return null;
        }

        $pool = $this->pool($this->collect($picks)['products']);
        $byKey = [];
        $byCode = [];

        foreach ($pool as $product) {
            $byKey[$product['key']] = $product;
            $byCode[$product['code']] = $product;
        }

        $targets = [];

        foreach ($proposed as $move) {
            $from = $this->code($move['from']);
            $to = $this->code($move['to']);
            $holder = $byCode[$from] ?? null;

            if ($holder === null || ! $this->sameProduct($holder['product'], $holder['sku'], $move['product'], $move['sku'])) {
                return null;
            }

            if ($from === $to || $this->drugRoom($to) || $this->packing($to) || ! isset($byCode[$to]) || isset($targets[$holder['key']])) {
                return null;
            }

            $targets[$holder['key']] = $byCode[$to];
        }

        $destinations = array_map(fn (array $slot): string => $slot['code'], $targets);

        if (count($destinations) !== count(array_unique($destinations))) {
            return null;
        }

        foreach ($destinations as $code) {
            if (! isset($targets[$byCode[$code]['key']])) {
                return null;
            }
        }

        $starts = [];

        foreach (array_keys($targets) as $key) {
            $starts[] = $byKey[$key]['code'];
        }

        sort($starts);
        sort($destinations);

        if ($starts !== $destinations) {
            return null;
        }

        foreach ($pool as $product) {
            if (! isset($targets[$product['key']])) {
                $targets[$product['key']] = $product;
            }
        }

        $suggestions = $this->describe($pool, $targets, 1);

        return $suggestions === [] ? null : $suggestions;
    }

    /**
     * Lower is closer to packing. Drug rooms and the packing zone are not general destinations.
     */
    public function seconds(string $location): int
    {
        $code = $this->code($location);

        if ($this->drugRoom($code) || $this->packing($code)) {
            return 10000;
        }

        $parts = explode(':', $code);
        $building = $parts[0];
        $aisle = (int) ($parts[1] ?? 0);
        $section = strtoupper($parts[2] ?? '');
        $level = (int) ($parts[3] ?? 1);
        $seconds = 40;

        if ($building === 'UP') {
            $seconds += 160;
        }

        $seconds += max(0, $aisle - 4) * 20;
        $letter = substr($section, -1);

        if (strlen($letter) === 1 && $letter >= 'A' && $letter <= 'Z') {
            $seconds += (ord($letter) - ord('A')) * 6;
        }

        if ($building !== 'UP' && $level >= 2) {
            $seconds += 30;
        }

        return $seconds;
    }

    /**
     * @param  array<int, array{product: string, sku: string, quantity: float, location: string, pharma: bool}>  $picks
     * @return array{products: array<string, array<string, mixed>>, pharma_left: int}
     */
    private function collect(array $picks): array
    {
        $products = [];

        foreach ($picks as $pick) {
            $code = $this->code($pick['location']);
            $key = $this->identity($pick['product'], $pick['sku']).'|'.$code;

            if (! isset($products[$key])) {
                $products[$key] = [
                    'key' => $key,
                    'product' => $pick['product'],
                    'sku' => $pick['sku'],
                    'location' => $pick['location'],
                    'code' => $code,
                    'seconds' => $this->seconds($pick['location']),
                    'pharma' => $pick['pharma'] || $this->drugRoom($code),
                    'packing' => $this->packing($code),
                    'demand' => 0.0,
                ];
            }

            $products[$key]['demand'] += $pick['quantity'];
            $products[$key]['pharma'] = $products[$key]['pharma'] || $pick['pharma'] || $this->drugRoom($code);
            $products[$key]['packing'] = $products[$key]['packing'] || $this->packing($code);
        }

        return [
            'products' => $products,
            'pharma_left' => count(array_filter($products, fn (array $product): bool => $product['pharma'] || $this->drugRoom($product['code']))),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    private function pool(array $products): array
    {
        $grouped = [];

        foreach ($products as $product) {
            if ($product['pharma'] || $product['packing'] || $this->drugRoom($product['code']) || $this->packing($product['code'])) {
                continue;
            }

            $grouped[$product['code']][] = $product;
        }

        $pool = [];

        foreach ($grouped as $group) {
            if (count($group) === 1) {
                $pool[] = $group[0];
            }
        }

        return $pool;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pool
     * @param  array<string, array<string, mixed>>  $targets
     * @return array<int, array<string, mixed>>
     */
    private function describe(array $pool, array $targets, int $minimumImprovement): array
    {
        $byKey = [];
        $occupant = [];

        foreach ($pool as $product) {
            $byKey[$product['key']] = $product;
            $occupant[$product['code']] = $product['key'];
        }

        $improving = [];

        foreach ($pool as $product) {
            $target = $targets[$product['key']];
            $improvement = $product['seconds'] - $target['seconds'];

            if ($product['demand'] >= 2 && $improvement >= $minimumImprovement) {
                $improving[] = [
                    'product' => $product,
                    'saving' => $product['demand'] * $improvement,
                ];
            }
        }

        usort($improving, fn (array $left, array $right): int => $right['saving'] <=> $left['saving']);
        $mentioned = [];
        $suggestions = [];

        foreach ($improving as $item) {
            $lead = $item['product'];

            if (isset($mentioned[$lead['key']]) || count($suggestions) >= 12) {
                continue;
            }

            $cycle = [$lead];
            $guard = 0;
            $current = $lead;

            while ($guard++ < count($pool)) {
                $nextKey = $occupant[$targets[$current['key']]['code']] ?? null;

                if ($nextKey === null || $nextKey === $lead['key']) {
                    break;
                }

                $current = $byKey[$nextKey];
                $cycle[] = $current;
                $mentioned[$current['key']] = true;

                if ($targets[$current['key']]['code'] === $lead['code']) {
                    break;
                }
            }

            $mentioned[$lead['key']] = true;
            $displaced = $cycle[1] ?? null;
            $follows = [];

            foreach (array_slice($cycle, 2) as $product) {
                $follows[] = $this->placement($product, $targets[$product['key']]);
            }

            $suggestion = [
                'product' => $lead['product'],
                'sku' => $lead['sku'],
                'from' => $lead['location'],
                'to' => $targets[$lead['key']]['location'],
                'picks' => $lead['demand'],
                'follows' => $follows,
            ];

            if (is_array($displaced)) {
                $suggestion['displaced'] = $this->placement($displaced, $targets[$displaced['key']]);
            }

            $suggestions[] = $suggestion;
        }

        return $suggestions;
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $target
     * @return array{product: string, sku: string, from: string, to: string, picks: float}
     */
    private function placement(array $product, array $target): array
    {
        return [
            'product' => $product['product'],
            'sku' => $product['sku'],
            'from' => $product['location'],
            'to' => $target['location'],
            'picks' => $product['demand'],
        ];
    }

    private function drugRoom(string $code): bool
    {
        return str_starts_with($this->code($code), '7L:');
    }

    private function packing(string $location): bool
    {
        return str_contains(strtoupper($location), 'PACKING');
    }

    private function code(string $location): string
    {
        $location = strtoupper(trim($location));

        if (str_contains($location, '/')) {
            $location = substr($location, (int) strrpos($location, '/') + 1);
        }

        return trim($location);
    }

    private function sameProduct(string $product, string $sku, string $otherProduct, string $otherSku): bool
    {
        if ($sku !== '' && $otherSku !== '' && strtolower($sku) === strtolower($otherSku)) {
            return true;
        }

        return strtolower($product) === strtolower($otherProduct);
    }

    private function identity(string $product, string $sku): string
    {
        return strtolower($sku !== '' ? $sku : $product);
    }
}
