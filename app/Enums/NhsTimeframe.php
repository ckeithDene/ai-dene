<?php

namespace App\Enums;

enum NhsTimeframe: string
{
    case Immediate = 'immediate';
    case Short = 'short';
    case Medium = 'medium';
    case Long = 'long';

    public function label(): string
    {
        return match ($this) {
            self::Immediate => __('Immediate, 0–3 months'),
            self::Short => __('Short term, 3–12 months'),
            self::Medium => __('Medium term, 1–2 years'),
            self::Long => __('Long term, 2–5 years'),
        };
    }
}
