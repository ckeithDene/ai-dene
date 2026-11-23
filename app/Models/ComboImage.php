<?php

namespace App\Models;

use Database\Factories\ComboImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $uuid
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
#[Fillable(['user_id', 'uuid', 'source'])]
class ComboImage extends Model
{
    /** @use HasFactory<ComboImageFactory> */
    use HasFactory;

    /**
     * The person who downloaded this image.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Gallery images are addressed by their file id.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
