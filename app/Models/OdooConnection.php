<?php

namespace App\Models;

use Database\Factories\OdooConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $url
 * @property string $database
 * @property string $username
 * @property string $api_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['url', 'database', 'username', 'api_key'])]
#[Hidden(['api_key'])]
class OdooConnection extends Model
{
    /** @use HasFactory<OdooConnectionFactory> */
    use HasFactory;

    /**
     * Get the stored Odoo connection, if one has been saved.
     */
    public static function current(): ?self
    {
        return static::query()->oldest('id')->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
        ];
    }
}
