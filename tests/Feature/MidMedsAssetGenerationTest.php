<?php

use App\Enums\PageAccess;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('midmeds-asset-generation'))
        ->assertRedirect(route('login'));
});

test('a member can open midmeds asset generation', function () {
    $member = User::factory()->create();
    $member->forceFill(['allowed_pages' => [PageAccess::MidMedsAssetGeneration->value]])->save();

    $this->actingAs($member)
        ->get(route('midmeds-asset-generation'))
        ->assertOk()
        ->assertSee('MidMeds Asset Generation')
        ->assertSee('Generate product assets for the MidMeds shop.');

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('MidMeds')
        ->assertSee('MidMeds Asset Generation')
        ->assertDontSee('First Order Diamonds')
        ->assertDontSee('Dene Healthcare');
});
