<?php

namespace App\Enums;

enum SalesStrategyStatus: string
{
    case Pending = 'pending';
    case Complete = 'complete';
    case Failed = 'failed';
}
