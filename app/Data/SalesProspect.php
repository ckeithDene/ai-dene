<?php

namespace App\Data;

final class SalesProspect
{
    /**
     * @param  array<int, array{quantity: string, name: string}>  $orderLines
     */
    public function __construct(
        public int $partnerId,
        public string $companyName,
        public ?string $website,
        public ?string $address,
        public ?string $overview,
        public ?string $companySize,
        public ?string $estimatedValue,
        public ?int $estimatedValueGbp,
        public ?string $marketShare,
        public ?string $knownSpend,
        public bool $existingCustomer,
        public bool $onBiggestFish,
        public bool $onFirstOrderDiamonds,
        public ?string $logoUrl,
        public array $orderLines,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'company_name' => $this->companyName,
            'company_website' => $this->website,
            'address' => $this->address,
            'overview' => $this->overview,
            'company_size' => $this->companySize,
            'estimated_value' => $this->estimatedValue,
            'estimated_value_gbp' => $this->estimatedValueGbp,
            'market_share' => $this->marketShare,
            'known_spend_with_midmeds' => $this->knownSpend,
            'existing_customer' => $this->existingCustomer,
            'known_order_lines' => $this->orderLines,
            'sales_objective' => 'Develop a profitable long-term purchasing relationship',
        ];
    }

    public function initial(): string
    {
        $letter = mb_substr(trim($this->companyName), 0, 1);

        return mb_strtoupper($letter !== '' ? $letter : '?');
    }
}
