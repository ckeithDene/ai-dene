<?php

namespace App\Data;

readonly class CompanyBrief
{
    public function __construct(
        public bool $isRegisteredCompany,
        public string $overview,
        public string $companySize,
        public string $estimatedValue,
        public ?int $estimatedValueGbp,
        public string $marketShare,
        public ?string $logoUrl,
        public ?string $website,
    ) {}
}
