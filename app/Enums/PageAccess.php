<?php

namespace App\Enums;

enum PageAccess: string
{
    case FirstOrderDiamonds = 'first-order-diamonds';
    case BiggestFish = 'biggest-fish';
    case GoogleShoppingSuggestions = 'google-shopping-suggestions';
    case PrimaryCareSpaces = 'primary-care-spaces';
    case NhsPurchasingPatterns = 'nhs-purchasing-patterns';
    case WarehouseLab = 'warehouse-lab';

    public function label(): string
    {
        return match ($this) {
            self::FirstOrderDiamonds => __('First Order Diamonds'),
            self::BiggestFish => __('Biggest Fish'),
            self::GoogleShoppingSuggestions => __('Google Shopping Suggestions'),
            self::PrimaryCareSpaces => __('Primary Care Spaces'),
            self::NhsPurchasingPatterns => __('NHS Purchasing Patterns'),
            self::WarehouseLab => __('Warehouse Lab'),
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $page): array => [
                'value' => $page->value,
                'label' => $page->label(),
            ],
            self::cases(),
        );
    }
}
