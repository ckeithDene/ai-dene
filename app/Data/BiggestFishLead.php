<?php

namespace App\Data;

readonly class BiggestFishLead
{
    public function __construct(
        public int $partnerId,
        public string $companyName,
        public string $address,
        public ?string $website,
        public ?string $logoContents,
        public string $totalSpent,
        public ?string $currency,
    ) {}
}
