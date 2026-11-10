<?php

namespace App\Services;

class WarehouseMovePlanner
{
    /**
     * Suggest closer bins for products that are picked often.
     *
     * Pharma and drug-room stock stay where they are. When a product takes a bin that is already in use, the product that was there moves into the bin that becomes free.
     *
     * @param  array<int, array{product: string, sku: string, quantity: float, location: string, pharma: bool}>  $picks
     * @return array{suggestions: array<int, array<string, mixed>>, pharma_left: int}
     */
    public function suggest(array $picks): array
    {
        $products = [];

        foreach ($picks as $pick) {
            $location = $this->code($pick['location']);
            $key = $this->key($pick['product'], $pick['sku'], $location);

            if (! isset($products[$key])) {
                $products[$key] = [
                    'product' => $pick['product'],
                    'sku' => $pick['sku'],
                    'location' => $pick['location'],
                    'code' => $location,
                    'pharma' => $pick['pharma'] || $this->drugRoom($location),
                    'demand' => 0.0,
                ];
            }

            $products[$key]['demand'] += $pick['quantity'];
            $products[$key]['pharma'] = $products[$key]['pharma'] || $pick['pharma'] || $this->drugRoom($location);
        }

        $movable = array_values(array_filter(
            $products,
            fn (array $product): bool => ! $product['pharma'] && ! $this->drugRoom($product['code']) && $product['demand'] >= 2,
        ));
        usort($movable, fn (array $left, array $right): int => $right['demand'] <=> $left['demand']);

        $claimed = [];
        $suggestions = [];

        foreach ($movable as $mover) {
            $moverKey = $this->key($mover['product'], $mover['sku'], $mover['code']);

            if (isset($claimed[$moverKey]) || count($suggestions) >= 12) {
                continue;
            }

            $moverScore = $this->seconds($mover['code']);
            $best = null;
            $bestSaving = 0.0;

            foreach ($products as $other) {
                $otherKey = $this->key($other['product'], $other['sku'], $other['code']);

                if ($otherKey === $moverKey || isset($claimed[$otherKey]) || $other['pharma'] || $this->drugRoom($other['code'])) {
                    continue;
                }

                if ($other['demand'] >= $mover['demand'] || $other['code'] === $mover['code']) {
                    continue;
                }

                $otherScore = $this->seconds($other['code']);
                $improvement = $moverScore - $otherScore;

                if ($improvement < 15) {
                    continue;
                }

                $saving = ($mover['demand'] - $other['demand']) * $improvement;

                if ($saving > $bestSaving) {
                    $bestSaving = $saving;
                    $best = $other;
                }
            }

            if ($best === null) {
                continue;
            }

            $claimed[$moverKey] = true;
            $claimed[$this->key($best['product'], $best['sku'], $best['code'])] = true;
            $suggestions[] = [
                'product' => $mover['product'],
                'sku' => $mover['sku'],
                'from' => $mover['location'],
                'to' => $best['location'],
                'picks' => $mover['demand'],
                'displaced' => [
                    'product' => $best['product'],
                    'sku' => $best['sku'],
                    'from' => $best['location'],
                    'to' => $mover['location'],
                    'picks' => $best['demand'],
                ],
            ];
        }

        return [
            'suggestions' => $suggestions,
            'pharma_left' => count(array_filter($products, fn (array $product): bool => $product['pharma'] || $this->drugRoom($product['code']))),
        ];
    }

    /**
     * Lower is closer to packing. Drug rooms are not used as general destinations.
     */
    public function seconds(string $location): int
    {
        $code = $this->code($location);

        if ($this->drugRoom($code)) {
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

    private function drugRoom(string $code): bool
    {
        return str_starts_with($this->code($code), '7L:');
    }

    private function code(string $location): string
    {
        $location = strtoupper(trim($location));

        if (str_contains($location, '/')) {
            $location = substr($location, (int) strrpos($location, '/') + 1);
        }

        return trim($location);
    }

    private function key(string $product, string $sku, string $code): string
    {
        return strtolower($sku !== '' ? $sku : $product).'|'.$code;
    }
}
