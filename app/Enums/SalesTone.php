<?php

namespace App\Enums;

enum SalesTone: string
{
    case AiSelects = 'ai';
    case Consultative = 'consultative';
    case Direct = 'direct';

    public function label(): string
    {
        return match ($this) {
            self::AiSelects => __('AI selects the tone for each company'),
            self::Consultative => __('Favour consultative selling'),
            self::Direct => __('Prefer direct commercial pitches'),
        };
    }
}
