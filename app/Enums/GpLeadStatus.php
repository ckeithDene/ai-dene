<?php

namespace App\Enums;

enum GpLeadStatus: string
{
    case Active = 'active';
    case Watchlist = 'watchlist';
    case Inactive = 'inactive';
}
