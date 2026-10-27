<?php

namespace App\Enums;

enum NhsDemandDirection: string
{
    case StrongIncrease = 'strong_increase';
    case ModerateIncrease = 'moderate_increase';
    case Stable = 'stable';
    case ModerateDecrease = 'moderate_decrease';
    case StrongDecrease = 'strong_decrease';
    case Uncertain = 'uncertain';

    public function label(): string
    {
        return match ($this) {
            self::StrongIncrease => __('Strong increase'),
            self::ModerateIncrease => __('Moderate increase'),
            self::Stable => __('Stable'),
            self::ModerateDecrease => __('Moderate decrease'),
            self::StrongDecrease => __('Strong decrease'),
            self::Uncertain => __('Uncertain'),
        };
    }

    public function isGrowth(): bool
    {
        return $this === self::StrongIncrease || $this === self::ModerateIncrease;
    }

    public function isDecline(): bool
    {
        return $this === self::StrongDecrease || $this === self::ModerateDecrease;
    }

    public function outlookLabel(): string
    {
        return match (true) {
            $this->isGrowth() => __('Opportunity'),
            $this->isDecline() => __('Pressure'),
            default => $this->label(),
        };
    }

    public function outlookOrder(): int
    {
        return match (true) {
            $this->isGrowth() => 0,
            $this->isDecline() => 1,
            default => 2,
        };
    }
}
