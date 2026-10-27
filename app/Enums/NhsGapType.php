<?php

namespace App\Enums;

enum NhsGapType: string
{
    case Directive = 'directive';
    case Complementary = 'complementary';
    case Replacement = 'replacement';
    case Solution = 'solution';
    case CustomerDemand = 'customer_demand';

    public function label(): string
    {
        return match ($this) {
            self::Directive => __('Directive-driven gap'),
            self::Complementary => __('Complementary product gap'),
            self::Replacement => __('Replacement product gap'),
            self::Solution => __('Complete solution gap'),
            self::CustomerDemand => __('Customer demand gap'),
        };
    }
}
