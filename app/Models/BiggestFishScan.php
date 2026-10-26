<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $last_order_id
 */
class BiggestFishScan extends Model
{
    /**
     * The scan remembers how far through confirmed orders the hourly check has walked.
     */
    public static function current(): self
    {
        $scan = static::query()->first();

        if ($scan instanceof self) {
            return $scan;
        }

        $scan = new self;
        $scan->last_order_id = 0;
        $scan->save();

        return $scan;
    }
}
