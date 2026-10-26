<?php

namespace App\Data;

readonly class DiamondCheckResult
{
    public function __construct(
        public int $reviewed,
        public ?string $skippedReason,
    ) {}
}
