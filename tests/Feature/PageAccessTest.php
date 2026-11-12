<?php

use App\Enums\PageAccess;
use App\Enums\TeamRole;
use App\Models\BiggestFish;
use App\Models\FirstOrderDiamond;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('a member without a page cannot open it', function (string $name, array $parameters) {
    $member = User::factory()->create();
    $member->forceFill(['allowed_pages' => []])->save();

    $this->actingAs($member)
        ->get(route($name, $parameters))
        ->assertForbidden();
})->with([
    'first order diamonds' => ['first-order-diamonds', []],
    'biggest fish' => ['biggest-fish', []],
    'google shopping suggestions' => ['google-shopping-suggestions', []],
    'primary care spaces' => ['primary-care-spaces', []],
    'a primary care project' => ['primary-care-spaces.show', ['lead' => 1]],
    'nhs purchasing patterns' => ['nhs-purchasing-patterns', []],
    'an nhs directive' => ['nhs-purchasing-patterns.directives.show', ['directive' => 1]],
    'an nhs purchasing pattern' => ['nhs-purchasing-patterns.show', ['opportunity' => 1]],
    'warehouse lab' => ['warehouse-lab', []],
    'warehouse moves' => ['warehouse-lab.moves', []],
    'warehouse orders' => ['warehouse-lab.orders.index', []],
    'a warehouse order' => ['warehouse-lab.orders.show', ['order' => 1]],
    'a warehouse file' => ['warehouse-lab.files', ['path' => 'index.html']],
    'a company' => ['companies.show', ['partner' => 1]],
]);

test('a member without page access cannot open a stored logo', function (string $name, string $model) {
    $member = User::factory()->create();
    $member->forceFill(['allowed_pages' => []])->save();
    $record = $model::factory()->create();

    $this->actingAs($member)
        ->get(route($name, $record))
        ->assertForbidden();
})->with([
    'first order diamond' => ['first-order-diamonds.logo', FirstOrderDiamond::class],
    'biggest fish' => ['biggest-fish.logo', BiggestFish::class],
]);

test('company research stays closed when only an unrelated page is allowed', function () {
    $member = User::factory()->create();
    $member->forceFill(['allowed_pages' => [PageAccess::GoogleShoppingSuggestions->value]])->save();

    $this->actingAs($member)
        ->get(route('companies.show', ['partner' => 1]))
        ->assertForbidden();
});

test('company research reaches a missing company when biggest fish is allowed', function () {
    $member = User::factory()->create();
    $member->forceFill(['allowed_pages' => [PageAccess::BiggestFish->value]])->save();

    $this->actingAs($member)
        ->get(route('companies.show', ['partner' => 1]))
        ->assertNotFound();
});

test('an empty page group is left out of the sidebar', function () {
    $member = User::factory()->create();
    $member->forceFill(['allowed_pages' => [PageAccess::WarehouseLab->value]])->save();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Warehouse Lab')
        ->assertSee('Dene Healthcare')
        ->assertDontSee('MidMeds');
});

test('an application admin can open a page with no boxes checked', function () {
    $admin = User::factory()->admin()->create();
    $admin->forceFill(['allowed_pages' => []])->save();

    $this->actingAs($admin)
        ->get(route('biggest-fish'))
        ->assertOk();
});

test('unchecking a page hides it and blocks the url', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $pages = collect(PageAccess::values())
        ->reject(fn (string $value): bool => $value === PageAccess::BiggestFish->value)
        ->sort()
        ->values()
        ->all();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('memberPages.'.$member->id, $pages)
        ->assertHasNoErrors();

    expect($member->fresh()->allowed_pages)->toBe($pages);

    $this->actingAs($member->fresh())
        ->get(route('biggest-fish'))
        ->assertForbidden();

    $this->get(route('first-order-diamonds'))
        ->assertOk();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('First Order Diamonds')
        ->assertSee('Warehouse Lab')
        ->assertDontSee('Biggest Fish');
});

test('checking every page leaves access unrestricted', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $member->forceFill(['allowed_pages' => []])->save();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('memberPages.'.$member->id, PageAccess::values())
        ->assertHasNoErrors();

    expect($member->fresh()->allowed_pages)->toBeNull();

    $this->actingAs($member->fresh())
        ->get(route('warehouse-lab'))
        ->assertOk();
});

test('a team owner sees a checkbox for each page', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($owner)
        ->get(route('teams.edit', $team))
        ->assertOk()
        ->assertSee('Pages they can view')
        ->assertSee('data-test="member-page-'.$member->id.'-warehouse-lab"', false);
});

test('a team member cannot see or change page checkboxes', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->get(route('teams.edit', $team))
        ->assertOk()
        ->assertDontSee('Pages they can view');

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('memberPages.'.$owner->id, [PageAccess::BiggestFish->value])
        ->assertForbidden();

    expect($owner->fresh()->allowed_pages)->toBeNull();
});

test('a team admin cannot change page access', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($admin);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('memberPages.'.$member->id, [PageAccess::BiggestFish->value])
        ->assertForbidden();

    expect($member->fresh()->allowed_pages)->toBeNull();
});

test('an application admin can grant a page without owning the team', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $member->forceFill(['allowed_pages' => []])->save();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Member->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($admin);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('memberPages.'.$member->id, [PageAccess::WarehouseLab->value])
        ->assertHasNoErrors();

    expect($member->fresh()->allowed_pages)->toBe([PageAccess::WarehouseLab->value]);
});

test('page access for an application admin cannot be turned off', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $admin->forceFill(['allowed_pages' => []])->save();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Member->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->assertSee('Admins can view every page.')
        ->set('memberPages.'.$admin->id, [PageAccess::BiggestFish->value])
        ->assertForbidden();

    expect($admin->fresh()->allowed_pages)->toBe([]);
});

test('page access cannot be changed for someone outside the team', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('memberPages.'.$outsider->id, [PageAccess::BiggestFish->value])
        ->assertNotFound();

    expect($outsider->fresh()->allowed_pages)->toBeNull();
});

test('an unknown page is rejected', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('memberPages.'.$member->id, ['not-a-page'])
        ->assertHasErrors(['pages.0' => 'The selected pages.0 is invalid.']);

    expect($member->fresh()->allowed_pages)->toBeNull();
});

test('page access must be a list', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('memberPages.'.$member->id, 'biggest-fish')
        ->assertHasErrors(['pages' => 'The pages field must be an array.']);

    expect($member->fresh()->allowed_pages)->toBeNull();
});
