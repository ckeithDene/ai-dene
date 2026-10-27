<?php

namespace App\Enums;

enum NhsSourcingAction: string
{
    case Add = 'add';
    case SourceOnDemand = 'source_on_demand';
    case Bundle = 'bundle';
    case FindSupplier = 'find_supplier';
    case Replace = 'replace';
    case Monitor = 'monitor';
    case NoAction = 'no_action';

    public function label(): string
    {
        return match ($this) {
            self::Add => __('Add to catalogue'),
            self::SourceOnDemand => __('Source on demand'),
            self::Bundle => __('Bundle existing products'),
            self::FindSupplier => __('Find a supplier'),
            self::Replace => __('Investigate a replacement'),
            self::Monitor => __('Monitor'),
            self::NoAction => __('No action'),
        };
    }
}
