<?php

namespace App\Enums;

enum GpDevelopmentType: string
{
    case NewBuilding = 'new_building';
    case Extension = 'extension';
    case Refurbishment = 'refurbishment';
    case ClinicalRooms = 'clinical_rooms';
    case Relocation = 'relocation';

    public function label(): string
    {
        return match ($this) {
            self::NewBuilding => __('New surgery building'),
            self::Extension => __('Extension'),
            self::Refurbishment => __('Refurbishment'),
            self::ClinicalRooms => __('New clinical rooms'),
            self::Relocation => __('Relocation'),
        };
    }
}
