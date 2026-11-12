<?php

namespace App\Models;

use App\Concerns\HasTeams;
use App\Enums\PageAccess;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property bool $is_admin
 * @property array<int, string>|null $allowed_pages
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 */
#[Fillable(['name', 'email', 'password', 'current_team_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable;

    /**
     * Determine whether the user is an application admin.
     */
    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    /**
     * A missing list means every feature page is still open.
     * An empty list hides them. Application admins always have access.
     */
    public function canViewPage(PageAccess $page): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->allowed_pages === null) {
            return true;
        }

        return in_array($page->value, $this->allowed_pages, true);
    }

    /**
     * Checked pages for the team access form.
     *
     * @return array<int, string>
     */
    public function viewablePageValues(): array
    {
        if ($this->allowed_pages === null) {
            return PageAccess::values();
        }

        return array_values(array_filter(
            PageAccess::values(),
            fn (string $page): bool => in_array($page, $this->allowed_pages, true),
        ));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'allowed_pages' => 'array',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
