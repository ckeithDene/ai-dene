<?php

namespace App\Enums;

enum CustomerReviewStatus: string
{
    case Pending = 'pending';
    case Complete = 'complete';
    case Failed = 'failed';
}
