<?php

use App\Actions\JoinTeamFromInvitation;
use App\Concerns\PasswordValidationRules;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.auth')] #[Title('Join team')] class extends Component {
    use PasswordValidationRules;

    #[Locked]
    public string $code;

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(TeamInvitation $invitation): void
    {
        $this->code = $invitation->code;
    }

    /**
     * Create an account and join the invited team.
     */
    public function join(JoinTeamFromInvitation $join): void
    {
        $invitation = $this->pendingInvitation;

        if ($invitation === null || $this->accountExists) {
            $this->addError('invitation', __('This invitation can no longer be used to create an account.'));

            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ]);

        $user = $join->createAccount($invitation, $validated['name'], $validated['password']);

        Auth::login($user);
        session()->regenerate();
        session()->flash('team-invitation-accepted', true);

        $this->redirectRoute('dashboard', ['current_team' => $user->currentTeam?->slug], navigate: true);
    }

    /**
     * Join the team when the invited person is already logged in.
     */
    public function accept(JoinTeamFromInvitation $join): void
    {
        $invitation = $this->pendingInvitation;
        $user = Auth::user();

        if ($invitation === null || $user === null) {
            $this->addError('invitation', __('This invitation has expired or has already been used.'));

            return;
        }

        $join->accept($invitation, $user);

        session()->regenerate();
        session()->flash('team-invitation-accepted', true);

        $this->redirectRoute('dashboard', ['current_team' => $user->fresh()->currentTeam?->slug], navigate: true);
    }

    /**
     * @return TeamInvitation|null
     */
    #[Computed]
    public function pendingInvitation(): ?TeamInvitation
    {
        $invitation = TeamInvitation::query()
            ->with(['team', 'inviter'])
            ->where('code', $this->code)
            ->whereNull('accepted_at')
            ->first();

        if ($invitation === null || $invitation->isExpired()) {
            return null;
        }

        return $invitation;
    }

    #[Computed]
    public function accountExists(): bool
    {
        $invitation = $this->pendingInvitation;

        if ($invitation === null) {
            return false;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($invitation->email)])
            ->exists();
    }
}; ?>

<div class="flex flex-col gap-6">
    @if ($this->pendingInvitation === null)
        <x-auth-header :title="__('Invitation unavailable')" :description="__('This invitation has expired or has already been used.')" />
    @elseif ($this->accountExists)
        <x-auth-header
            :title="__('Join :team', ['team' => $this->pendingInvitation->team->name])"
            :description="__('An account already exists for :email. Log in to join this team.', ['email' => $this->pendingInvitation->email])"
        />

        @auth
            @if (strtolower(auth()->user()->email) === strtolower($this->pendingInvitation->email))
                <flux:button variant="primary" type="button" class="w-full" wire:click="accept" data-test="accept-invitation-button">
                    {{ __('Join team') }}
                </flux:button>
            @endif
        @else
            <flux:button variant="primary" class="w-full" :href="route('login', ['invitation' => $this->pendingInvitation->code])" data-test="invitation-login">
                {{ __('Log in') }}
            </flux:button>
        @endauth
    @else
        <x-auth-header
            :title="__('Join :team', ['team' => $this->pendingInvitation->team->name])"
            :description="__('Choose a password for :email.', ['email' => $this->pendingInvitation->email])"
        />

        <form wire:submit="join" class="flex flex-col gap-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" data-test="invitation-name" />

            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                viewable
                data-test="invitation-password"
            />

            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />

            <flux:error name="invitation" />

            <flux:button variant="primary" type="submit" class="w-full" data-test="join-team-button">
                {{ __('Join team') }}
            </flux:button>
        </form>
    @endif
</div>
