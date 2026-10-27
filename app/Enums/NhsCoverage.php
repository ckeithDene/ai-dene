<?php

namespace App\Enums;

enum NhsCoverage: string
{
    case Covered = 'covered';
    case Partial = 'partial';
    case PotentialGap = 'potential_gap';
    case Substitute = 'substitute';
    case Uncertain = 'uncertain';

    public function label(): string
    {
        return match ($this) {
            self::Covered => __('Covered'),
            self::Partial => __('Partially covered'),
            self::PotentialGap => __('Potential gap'),
            self::Substitute => __('Substitute available'),
            self::Uncertain => __('Catalogue matching required'),
        };
    }
}
