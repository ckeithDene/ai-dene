<?php

namespace App\Enums;

enum OdooCompany: string
{
    case MidMeds = 'midmeds';
    case Dene = 'dene';

    public function label(): string
    {
        return match ($this) {
            self::MidMeds => __('MidMeds'),
            self::Dene => __('Dene Healthcare'),
        };
    }
}
