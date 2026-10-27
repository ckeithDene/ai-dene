<?php

namespace App\Enums;

enum ResearchDepth: string
{
    case Quick = 'quick';
    case Comprehensive = 'comprehensive';

    public function label(): string
    {
        return match ($this) {
            self::Quick => __('Quick prospect qualification'),
            self::Comprehensive => __('Comprehensive sales research'),
        };
    }
}
