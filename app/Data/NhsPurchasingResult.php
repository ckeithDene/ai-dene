<?php

namespace App\Data;

final class NhsPurchasingResult
{
    public function __construct(
        public int $directives,
        public int $opportunities,
        public ?string $skippedReason = null,
    ) {}
}
