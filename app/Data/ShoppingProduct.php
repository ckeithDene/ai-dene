<?php

namespace App\Data;

readonly class ShoppingProduct
{
    public function __construct(
        public int $productId,
        public string $name,
        public string $sku,
        public string $barcode,
        public float $unitsSold,
        public ?float $salesPrice,
        public ?float $costPrice,
    ) {}
}
