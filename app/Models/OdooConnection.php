<?php

namespace App\Models;

use App\Enums\OdooCompany;
use Database\Factories\OdooConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property OdooCompany $company
 * @property string $url
 * @property string $database
 * @property string $username
 * @property string $api_key
 * @property Carbon|null $verified_at
 * @property string|null $verification_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['company', 'url', 'database', 'username', 'api_key'])]
#[Hidden(['api_key'])]
class OdooConnection extends Model
{
    /** @use HasFactory<OdooConnectionFactory> */
    use HasFactory;

    /**
     * Get the Odoo connection for one company.
     */
    public static function for(OdooCompany $company): ?self
    {
        return static::query()->where('company', $company)->first();
    }

    /**
     * Get the MidMeds Odoo connection.
     */
    public static function current(): ?self
    {
        return static::for(OdooCompany::MidMeds);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => OdooCompany::class,
            'api_key' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }
}
