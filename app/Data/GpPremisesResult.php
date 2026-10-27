<?php

namespace App\Data;

final class GpPremisesResult
{
    public function __construct(
        public int $added,
        public int $updated,
        public int $checked,
        public ?string $skippedReason = null,
    ) {}
}
