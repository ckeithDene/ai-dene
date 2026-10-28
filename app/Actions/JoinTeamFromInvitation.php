<?php

namespace App\Actions;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JoinTeamFromInvitation
{
    /**
     * Create an account from a pending invitation and join that team.
     */
    public function createAccount(TeamInvitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password): User {
            $invitation = $this->lockPending($invitation);
            $email = Str::lower($invitation->email);

            if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages([
                    'name' => __('An account already exists for this email address. Log in to join the team.'),
                ]);
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $user->email_verified_at = now();
            $user->save();

            $personalTeam = Team::query()->create([
                'name' => $user->name."'s Team",
                'is_personal' => true,
            ]);

            $personalTeam->members()->attach($user->id, [
                'role' => TeamRole::Owner->value,
            ]);

            $this->addToInvitedTeam($invitation, $user);

            return $user;
        });
    }

    /**
     * Join the invited team with an account that already uses the invitation email.
     */
    public function accept(TeamInvitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user): void {
            $invitation = $this->lockPending($invitation);

            if (Str::lower($user->email) !== Str::lower($invitation->email)) {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation was sent to a different email address.'),
                ]);
            }

            $this->addToInvitedTeam($invitation, $user);
        });
    }

    private function lockPending(TeamInvitation $invitation): TeamInvitation
    {
        $invitation = TeamInvitation::query()
            ->whereKey($invitation->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation has expired or has already been used.'),
            ]);
        }

        return $invitation;
    }

    private function addToInvitedTeam(TeamInvitation $invitation, User $user): void
    {
        $invitation->team->memberships()->firstOrCreate(
            ['user_id' => $user->id],
            ['role' => $invitation->role],
        );

        $invitation->update(['accepted_at' => now()]);

        $user->switchTeam($invitation->team);
    }
}
