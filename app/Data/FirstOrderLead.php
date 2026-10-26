<?php

namespace App\Data;

readonly class FirstOrderLead
{
    public function __construct(
        public int $partnerId,
        public int $orderId,
        public string $orderName,
        public ?string $orderedAt,
        public string $companyName,
        public string $address,
        public ?string $logoContents,
    ) {}
}
