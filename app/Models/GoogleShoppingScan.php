<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $next_rank
 */
class GoogleShoppingScan extends Model
{
    /**
     * The scan remembers how far down the best-seller list the hourly check has walked.
     */
    public static function current(): self
    {
        $scan = static::query()->first();

        if ($scan instanceof self) {
            return $scan;
        }

        $scan = new self;
        $scan->next_rank = 0;
        $scan->save();

        return $scan;
    }
}
