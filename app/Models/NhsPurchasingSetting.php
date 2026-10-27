<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $last_summary
 * @property string|null $last_changes
 * @property string|null $last_limitations
 * @property CarbonInterface|null $last_ran_at
 */
#[Fillable([
    'last_summary',
    'last_changes',
    'last_limitations',
    'last_ran_at',
])]
class NhsPurchasingSetting extends Model
{
    /**
     * Get the shared research summary, creating it when none exists.
     */
    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_ran_at' => 'datetime',
        ];
    }
}
