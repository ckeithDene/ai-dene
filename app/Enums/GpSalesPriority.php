<?php

namespace App\Enums;

enum GpSalesPriority: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => __('High priority'),
            self::Medium => __('Medium priority'),
            self::Low => __('Low priority'),
        };
    }
}
