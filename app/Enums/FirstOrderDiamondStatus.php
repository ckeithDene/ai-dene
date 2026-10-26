<?php

namespace App\Enums;

enum FirstOrderDiamondStatus: string
{
    case Pending = 'pending';
    case Complete = 'complete';
    case Failed = 'failed';
}
