<?php

namespace App\Data;

readonly class ShoppingPrice
{
    public function __construct(
        public ?float $cheapestPrice,
        public ?string $seller,
        public ?string $url,
        public ?string $matchedProduct,
        public ?string $notes,
    ) {}
}
