<?php

use App\Enums\OdooCompany;
use App\Models\BiggestFish;
use App\Models\FirstOrderDiamond;
use App\Models\OdooConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

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

    Http::assertNothingSent();

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
    $this->travelTo('2026-10-26 12:00:00');
    $this->actingAs(User::factory()->admin()->create());

    Http::fake([
        'https://acme.odoo.com/jsonrpc' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => 2,
        ]),
    ]);

    Livewire::test('pages::admin.odoo-connection')
        ->set('url', 'https://acme.odoo.com/')
        ->set('database', 'acme')
        ->set('username', ' admin ')
        ->set('apiKey', 'odoo-api-key')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('apiKey', '')
        ->assertSee('Connected');

    $this->assertDatabaseHas('odoo_connections', [
        'url' => 'https://acme.odoo.com',
        'database' => 'acme',
        'username' => 'admin',
    ]);

    $connection = OdooConnection::query()->sole();

    expect($connection->api_key)->toBe('odoo-api-key')
        ->and($connection->verified_at?->toDateTimeString())->toBe('2026-10-26 12:00:00')
        ->and($connection->verification_error)->toBeNull();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://acme.odoo.com/jsonrpc'
            && $request->data()['params']['method'] === 'authenticate'
            && $request->data()['params']['args'][0] === 'acme'
            && $request->data()['params']['args'][1] === 'admin'
            && $request->data()['params']['args'][2] === 'odoo-api-key';
    });

    $this->assertDatabaseMissing('odoo_connections', [
        'api_key' => 'odoo-api-key',
    ]);
});

test('saving a blank api key keeps the saved key', function () {
    $this->actingAs(User::factory()->admin()->create());

    Http::fake([
        'https://example.odoo.com/jsonrpc' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => 2,
        ]),
    ]);

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

    Http::fake([
        'https://example.odoo.com/jsonrpc' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => 2,
        ]),
    ]);

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

test('saving reports a rejected odoo connection', function () {
    $this->actingAs(User::factory()->admin()->create());

    Http::fake([
        'https://acme.odoo.com/jsonrpc' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => false,
        ]),
    ]);

    Livewire::test('pages::admin.odoo-connection')
        ->set('url', 'https://acme.odoo.com')
        ->set('database', 'acme')
        ->set('username', 'admin')
        ->set('apiKey', 'odoo-api-key')
        ->call('save')
        ->assertSee('Connection failed')
        ->assertSee('Odoo rejected these connection details.');

    $connection = OdooConnection::query()->sole();

    expect($connection->api_key)->toBe('odoo-api-key')
        ->and($connection->verified_at)->toBeNull()
        ->and($connection->verification_error)->toBe('Odoo rejected these connection details.');
});

test('saving reports when the odoo server cannot be reached', function () {
    $this->actingAs(User::factory()->admin()->create());

    Http::fake([
        'https://acme.odoo.com/jsonrpc' => Http::failedConnection(),
    ]);

    Livewire::test('pages::admin.odoo-connection')
        ->set('url', 'https://acme.odoo.com')
        ->set('database', 'acme')
        ->set('username', 'admin')
        ->set('apiKey', 'odoo-api-key')
        ->call('save')
        ->assertSee('Could not reach the Odoo server.');

    expect(OdooConnection::query()->sole()->verified_at)->toBeNull();
});

test('odoo verification does not follow redirects', function () {
    $this->actingAs(User::factory()->admin()->create());

    Http::fake([
        'https://acme.odoo.com/jsonrpc' => Http::response('', 302, [
            'Location' => 'https://evil.example/steal',
        ]),
    ]);

    Livewire::test('pages::admin.odoo-connection')
        ->set('url', 'https://acme.odoo.com')
        ->set('database', 'acme')
        ->set('username', 'admin')
        ->set('apiKey', 'odoo-api-key')
        ->call('save')
        ->assertSee('Could not reach the Odoo server.');

    Http::assertSentCount(1);

    expect(OdooConnection::query()->sole()->verification_error)->not->toContain('odoo-api-key');
});

test('admins can recheck a saved odoo connection', function () {
    $this->travelTo('2026-10-26 12:00:00');
    $this->actingAs(User::factory()->admin()->create());

    OdooConnection::factory()->create([
        'url' => 'https://acme.odoo.com',
        'verification_error' => 'Odoo rejected these connection details.',
    ]);

    Http::fake([
        'https://acme.odoo.com/jsonrpc' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => 2,
        ]),
    ]);

    Livewire::test('pages::admin.odoo-connection')
        ->call('testConnection')
        ->assertSee('Connected');

    $connection = OdooConnection::query()->sole();

    expect($connection->verified_at?->toDateTimeString())->toBe('2026-10-26 12:00:00')
        ->and($connection->verification_error)->toBeNull();
});

test('testing an unsaved odoo connection does not call odoo', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.odoo-connection')
        ->call('testConnection');

    Http::assertNothingSent();

    $this->assertDatabaseEmpty('odoo_connections');
});

test('the sidebar separates midmeds and dene healthcare', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['MidMeds', 'First Order Diamonds', 'Biggest Fish', 'Dene Healthcare', 'Primary Care Spaces', 'NHS Purchasing Patterns']);
});

test('dene healthcare can be saved beside the midmeds connection', function () {
    $this->actingAs(User::factory()->admin()->create());

    OdooConnection::factory()->create([
        'url' => 'https://midmeds.odoo.com',
        'database' => 'midmeds',
        'username' => 'midmeds-admin',
    ]);

    Http::fake([
        'https://dene.odoo.com/jsonrpc' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => 3,
        ]),
    ]);

    Livewire::test('pages::admin.odoo-connection')
        ->assertSet('url', 'https://midmeds.odoo.com')
        ->set('deneUrl', 'https://dene.odoo.com/')
        ->set('deneDatabase', 'dene')
        ->set('deneUsername', ' dene-admin ')
        ->set('deneApiKey', 'dene-key')
        ->call('saveDene')
        ->assertHasNoErrors()
        ->assertSet('deneApiKey', '');

    expect(OdooConnection::query()->count())->toBe(2)
        ->and(OdooConnection::for(OdooCompany::MidMeds)?->url)->toBe('https://midmeds.odoo.com')
        ->and(OdooConnection::for(OdooCompany::Dene)?->url)->toBe('https://dene.odoo.com')
        ->and(OdooConnection::for(OdooCompany::Dene)?->username)->toBe('dene-admin')
        ->and(OdooConnection::for(OdooCompany::Dene)?->api_key)->toBe('dene-key');
});

test('midmeds pages keep using the midmeds odoo connection', function () {
    $this->actingAs(User::factory()->create());

    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    OdooConnection::factory()->dene()->create(['url' => 'https://dene.odoo.com']);
    FirstOrderDiamond::factory()->create([
        'odoo_order_id' => 44,
        'company_name' => 'Acme Ltd',
    ]);
    BiggestFish::factory()->create([
        'odoo_partner_id' => 9,
        'company_name' => 'Big Co',
    ]);

    $this->get(route('first-order-diamonds'))
        ->assertOk()
        ->assertSee('https://midmeds.odoo.com/web#id=44&amp;model=sale.order&amp;view_type=form', false)
        ->assertDontSee('dene.odoo.com', false);

    $this->get(route('biggest-fish'))
        ->assertOk()
        ->assertSee('https://midmeds.odoo.com/web#id=9&amp;model=res.partner&amp;view_type=form', false)
        ->assertDontSee('dene.odoo.com', false);
});

test('non-admins cannot test the odoo connection', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user);

    OdooConnection::factory()->create();

    $component = Livewire::test('pages::admin.odoo-connection');

    $user->is_admin = false;
    $user->save();

    auth()->setUser($user->fresh());

    $component->call('testConnection')
        ->assertForbidden();

    Http::assertNothingSent();
});
