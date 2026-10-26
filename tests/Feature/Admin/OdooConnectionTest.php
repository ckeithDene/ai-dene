<?php

use App\Models\OdooConnection;
use App\Models\User;
use Livewire\Livewire;

test('admins are allowed through the admin gate', function () {
    $user = User::factory()->admin()->create();

    expect($user->can('accessAdmin'))->toBeTrue();
});

test('non-admins are denied by the admin gate', function () {
    $user = User::factory()->create();

    expect($user->can('accessAdmin'))->toBeFalse();
});

test('users cannot grant themselves admin through mass assignment', function () {
    $user = User::factory()->create();

    $user->update([
        'is_admin' => true,
    ]);

    expect($user->refresh()->isAdmin())->toBeFalse();
});

test('guests are redirected to the login page', function () {
    $this->get(route('admin.odoo'))
        ->assertRedirect(route('login'));
});

test('non-admins cannot view the odoo connection page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.odoo'))
        ->assertForbidden();
});

test('admins can view the odoo connection page', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.odoo'))
        ->assertOk()
        ->assertSee('Odoo connection');
});

test('admins are redirected from the admin index to the odoo connection', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertRedirect(route('admin.odoo'));
});

test('admins see the odoo connection link', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('admin.odoo'), false);
});

test('non-admins do not see the odoo connection link', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('admin.odoo'), false);
});

test('non-admins cannot open the odoo connection form', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::admin.odoo-connection')
        ->assertForbidden();

    $this->assertDatabaseEmpty('odoo_connections');
});

test('odoo connection requires a url, database, username, and api key', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.odoo-connection')
        ->set('url', '')
        ->set('database', '')
        ->set('username', '')
        ->set('apiKey', '')
        ->call('save')
        ->assertHasErrors([
            'url' => 'The URL field is required.',
            'database' => 'The database field is required.',
            'username' => 'The username field is required.',
            'apiKey' => 'The API key field is required.',
        ]);

    $this->assertDatabaseEmpty('odoo_connections');
});

test('odoo connection rejects a url that is not http or https', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.odoo-connection')
        ->set('url', 'ftp://files.example.com')
        ->set('database', 'acme')
        ->set('username', 'admin')
        ->set('apiKey', 'odoo-api-key')
        ->call('save')
        ->assertHasErrors([
            'url' => 'The URL field must be a valid URL.',
        ]);

    $this->assertDatabaseEmpty('odoo_connections');
});

test('odoo connection rejects a username longer than 255 characters', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.odoo-connection')
        ->set('url', 'https://acme.odoo.com')
        ->set('database', 'acme')
        ->set('username', str_repeat('a', 256))
        ->set('apiKey', 'odoo-api-key')
        ->call('save')
        ->assertHasErrors([
            'username' => 'The username field must not be greater than 255 characters.',
        ]);

    $this->assertDatabaseEmpty('odoo_connections');
});

test('admins can save the odoo connection', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.odoo-connection')
        ->set('url', 'https://acme.odoo.com/')
        ->set('database', 'acme')
        ->set('username', ' admin ')
        ->set('apiKey', 'odoo-api-key')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('apiKey', '');

    $this->assertDatabaseHas('odoo_connections', [
        'url' => 'https://acme.odoo.com',
        'database' => 'acme',
        'username' => 'admin',
    ]);

    expect(OdooConnection::query()->sole()->api_key)->toBe('odoo-api-key');

    $this->assertDatabaseMissing('odoo_connections', [
        'api_key' => 'odoo-api-key',
    ]);
});

test('saving a blank api key keeps the saved key', function () {
    $this->actingAs(User::factory()->admin()->create());

    OdooConnection::factory()->create([
        'api_key' => 'original-key',
    ]);

    Livewire::test('pages::admin.odoo-connection')
        ->set('database', 'acme-prod')
        ->set('apiKey', '   ')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('odoo_connections', [
        'database' => 'acme-prod',
    ]);

    expect(OdooConnection::query()->sole()->api_key)->toBe('original-key');
});

test('saving a new api key replaces the saved key', function () {
    $this->actingAs(User::factory()->admin()->create());

    OdooConnection::factory()->create([
        'api_key' => 'original-key',
    ]);

    Livewire::test('pages::admin.odoo-connection')
        ->set('apiKey', 'replacement-key')
        ->call('save')
        ->assertHasNoErrors();

    expect(OdooConnection::query()->sole()->api_key)->toBe('replacement-key');
});

test('the saved api key is not shown on the page', function () {
    $this->actingAs(User::factory()->admin()->create());

    OdooConnection::factory()->create([
        'url' => 'https://acme.odoo.com',
        'username' => 'odoo-admin',
        'api_key' => 'super-secret-key',
    ]);

    $this->get(route('admin.odoo'))
        ->assertOk()
        ->assertSee('https:\/\/acme.odoo.com', false)
        ->assertSee('odoo-admin', false)
        ->assertDontSee('super-secret-key', false);
});

test('odoo connection details are escaped on the page', function () {
    $this->actingAs(User::factory()->admin()->create());

    OdooConnection::factory()->create([
        'username' => '<script>alert(1)</script>',
    ]);

    $this->get(route('admin.odoo'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;\/script&gt;', false);
});
