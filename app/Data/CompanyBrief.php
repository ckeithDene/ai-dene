<?php

namespace App\Data;

readonly class CompanyBrief
{
    public function __construct(
        public string $overview,
        public string $companySize,
        public string $estimatedValue,
        public string $marketShare,
        public ?string $logoUrl,
    ) {}
}
