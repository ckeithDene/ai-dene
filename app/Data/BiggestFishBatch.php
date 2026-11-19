<?php

namespace App\Data;

readonly class BiggestFishBatch
{
    /**
     * @param  array<int, BiggestFishLead>  $customers
     */
    public function __construct(
        public array $customers,
        public int $cursor,
    ) {}
}
