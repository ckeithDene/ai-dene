<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('team invitations can be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.invite-member-modal', ['team' => $team])
        ->set('inviteEmail', 'invited@example.com')
        ->set('inviteRole', TeamRole::Member->value)
        ->call('createInvitation')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member->value,
    ]);
});

test('team invitations cannot be created by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.invite-member-modal', ['team' => $team])
        ->set('inviteEmail', 'invited@example.com')
        ->set('inviteRole', TeamRole::Member->value)
        ->call('createInvitation')
        ->assertForbidden();
});

test('team invitations can be cancelled by owner', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.cancel-invitation-modal', ['team' => $team])
        ->set('invitationCode', $invitation->code)
        ->call('cancelInvitation')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations can be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    $response = Livewire::test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code);

    $response->assertRedirect(route('dashboard'));

    expect(session('team-invitation-accepted'))->toBeTrue();

    expect($invitation->fresh()->accepted_at)->not->toBeNull();
    expect($invitedUser->fresh()->belongsToTeam($team))->toBeTrue();
    expect($invitedUser->fresh()->current_team_id)->toBe($team->id);
});

test('accepted team invitations cannot be accepted again', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    Livewire::test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code)
        ->assertStatus(404);
});

test('accepted invitation toast is shown on the dashboard', function () {
    $user = User::factory()->create();

    session()->flash('team-invitation-accepted', true);

    $this->actingAs($user);

    Livewire::test('pages::teams.pending-invitations-modal')
        ->assertDispatched('toast-show');
});

test('pending invitations excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Expired Team']);

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    Livewire::test('pages::teams.pending-invitations-modal')
        ->assertDontSee('Expired Team');

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations cannot be accepted by user that wasnt invited', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($uninvitedUser);

    $response = Livewire::test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code);

    $response->assertHasErrors(['invitation']);

    expect($uninvitedUser->fresh()->belongsToTeam($team))->toBeFalse();
});

test('expired invitations cannot be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    $response = Livewire::test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code);

    $response->assertHasErrors(['invitation']);

    expect($invitedUser->fresh()->belongsToTeam($team))->toBeFalse();
});

test('team invitation emails link to setting a password', function () {
    $invitation = TeamInvitation::factory()->create();

    $mail = (new TeamInvitationNotification($invitation))->toMail(new AnonymousNotifiable);

    expect($mail->actionUrl)->toBe(route('invitations.accept', $invitation))
        ->and($mail->actionText)->toBe('Join team');
});

test('a new person can set a password and join from the invitation', function () {
    $team = Team::factory()->create();
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'new.colleague@example.com',
        'role' => TeamRole::Member,
    ]);

    Livewire::test('pages::auth.accept-invitation', ['invitation' => $invitation])
        ->set('name', 'New Colleague')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('join')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', ['current_team' => $team->slug]));

    $user = User::query()->where('email', 'new.colleague@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->is_admin)->toBeFalse()
        ->and($user->current_team_id)->toBe($team->id)
        ->and($user->belongsToTeam($team))->toBeTrue()
        ->and($user->personalTeam())->not->toBeNull()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

test('an invitation does not offer a new password when the email already has an account', function () {
    $user = User::factory()->create(['email' => 'Invited@Example.com']);
    $invitation = TeamInvitation::factory()->create([
        'email' => 'invited@example.com',
    ]);

    $this->get(route('invitations.accept', $invitation))
        ->assertOk()
        ->assertSee('An account already exists for invited@example.com')
        ->assertSee(route('login', ['invitation' => $invitation->code]), false)
        ->assertDontSee('data-test="invitation-password"', false);

    Livewire::test('pages::auth.accept-invitation', ['invitation' => $invitation])
        ->set('name', 'Someone Else')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('join')
        ->assertHasErrors('invitation');

    expect(User::query()->whereKey($user->id)->count())->toBe(1);
    expect($invitation->fresh()->accepted_at)->toBeNull();
});

test('an expired invitation cannot be used to create an account', function () {
    $invitation = TeamInvitation::factory()->expired()->create();

    $this->get(route('invitations.accept', $invitation))
        ->assertOk()
        ->assertSee('This invitation has expired or has already been used.')
        ->assertDontSee('data-test="join-team-button"', false);
});

test('a password confirmation is required to join from an invitation', function () {
    $invitation = TeamInvitation::factory()->create([
        'email' => 'new.colleague@example.com',
    ]);

    Livewire::test('pages::auth.accept-invitation', ['invitation' => $invitation])
        ->set('name', 'New Colleague')
        ->set('password', 'password')
        ->set('password_confirmation', 'different-password')
        ->call('join')
        ->assertHasErrors('password');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'new.colleague@example.com']);
});

test('the login page links an invitation to the password form', function () {
    $invitation = TeamInvitation::factory()->create();

    $this->get(route('login', ['invitation' => $invitation->code]))
        ->assertOk()
        ->assertSee(route('invitations.accept', $invitation), false);
});
