<?php

namespace App\Services;

use App\Data\SalesProspect;
use App\Models\BiggestFish;
use App\Models\FirstOrderDiamond;
use RuntimeException;

class SalesProspectFinder
{
    /**
     * Find a reviewed company from either lead list.
     */
    public function find(int $partnerId): ?SalesProspect
    {
        $fish = BiggestFish::query()
            ->where('odoo_partner_id', $partnerId)
            ->where('is_registered_company', true)
            ->first();
        $diamond = FirstOrderDiamond::query()
            ->where('odoo_partner_id', $partnerId)
            ->where('is_registered_company', true)
            ->first();

        if ($fish !== null) {
            return $this->prospect($partnerId, $fish, $diamond);
        }

        if ($diamond !== null) {
            return $this->prospect($partnerId, null, $diamond);
        }

        return null;
    }

    private function prospect(int $partnerId, ?BiggestFish $fish, ?FirstOrderDiamond $diamond): SalesProspect
    {
        if ($fish !== null) {
            return new SalesProspect(
                partnerId: $partnerId,
                companyName: $fish->company_name,
                website: $fish->website ?? $diamond?->website,
                address: $fish->address ?? $diamond?->address,
                overview: $fish->overview ?? $diamond?->overview,
                companySize: $fish->company_size ?? $diamond?->company_size,
                estimatedValue: $fish->estimatedValueLabel(),
                estimatedValueGbp: $fish->estimated_value_gbp,
                marketShare: $fish->market_share ?? $diamond?->market_share,
                knownSpend: $fish->totalSpentLabel(),
                existingCustomer: $diamond !== null || (float) $fish->total_spent > 0,
                onBiggestFish: true,
                onFirstOrderDiamonds: $diamond !== null,
                logoUrl: $fish->logoUrl() ?? $diamond?->logoUrl(),
                orderLines: $this->orderLines($diamond),
            );
        }

        if ($diamond === null) {
            throw new RuntimeException('A sales prospect needs a reviewed company.');
        }

        return new SalesProspect(
            partnerId: $partnerId,
            companyName: $diamond->company_name,
            website: $diamond->website,
            address: $diamond->address,
            overview: $diamond->overview,
            companySize: $diamond->company_size,
            estimatedValue: $diamond->estimated_value,
            estimatedValueGbp: null,
            marketShare: $diamond->market_share,
            knownSpend: null,
            existingCustomer: true,
            onBiggestFish: false,
            onFirstOrderDiamonds: true,
            logoUrl: $diamond->logoUrl(),
            orderLines: $this->orderLines($diamond),
        );
    }

    /**
     * @return array<int, array{quantity: string, name: string}>
     */
    private function orderLines(?FirstOrderDiamond $diamond): array
    {
        if ($diamond === null) {
            return [];
        }

        $orderLines = [];

        foreach ($diamond->order_lines ?? [] as $line) {
            if ($line['name'] === '') {
                continue;
            }

            $orderLines[] = [
                'quantity' => $line['quantity'],
                'name' => $line['name'],
            ];
        }

        return $orderLines;
    }
}
