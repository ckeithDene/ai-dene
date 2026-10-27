<?php

namespace App\Enums;

enum SalesObjective: string
{
    case Balanced = 'balanced';
    case RepeatConsumables = 'repeat_consumables';
    case Equipment = 'equipment';
    case StrategicAccounts = 'strategic_accounts';

    public function label(): string
    {
        return match ($this) {
            self::Balanced => __('Balanced, for new accounts and long-term growth'),
            self::RepeatConsumables => __('Prioritise repeat consumable orders'),
            self::Equipment => __('Prioritise high-value equipment'),
            self::StrategicAccounts => __('Prioritise larger strategic accounts'),
        };
    }
}
