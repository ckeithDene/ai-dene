<?php

namespace App\Enums;

enum GpGeography: string
{
    case UnitedKingdom = 'uk';
    case England = 'england';
    case Scotland = 'scotland';
    case Wales = 'wales';
    case NorthernIreland = 'northern_ireland';

    public function label(): string
    {
        return match ($this) {
            self::UnitedKingdom => __('Entire United Kingdom'),
            self::England => __('England'),
            self::Scotland => __('Scotland'),
            self::Wales => __('Wales'),
            self::NorthernIreland => __('Northern Ireland'),
        };
    }
}
