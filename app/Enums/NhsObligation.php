<?php

namespace App\Enums;

enum NhsObligation: string
{
    case Binding = 'binding';
    case Contractual = 'contractual';
    case ProcurementPolicy = 'procurement_policy';
    case Guideline = 'guideline';
    case Funded = 'funded';
    case Strategic = 'strategic';
    case Proposal = 'proposal';
    case Inference = 'inference';

    public function label(): string
    {
        return match ($this) {
            self::Binding => __('Legally binding requirement'),
            self::Contractual => __('NHS contractual requirement'),
            self::ProcurementPolicy => __('Procurement policy'),
            self::Guideline => __('Clinical guideline'),
            self::Funded => __('Funded initiative'),
            self::Strategic => __('Strategic objective'),
            self::Proposal => __('Proposal or consultation'),
            self::Inference => __('Commercial inference'),
        };
    }

    public function canConfirmRequirement(): bool
    {
        return $this === self::Binding || $this === self::Contractual;
    }
}
