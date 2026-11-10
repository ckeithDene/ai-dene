<?php

use App\Models\OdooConnection;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('guests cannot open the warehouse lab', function () {
    $this->get(route('warehouse-lab'))
        ->assertRedirect(route('login'));

    $this->get(route('warehouse-lab.files', ['path' => 'index.html']))
        ->assertRedirect(route('login'));

    $this->get(route('warehouse-lab.files', ['path' => 'location-catalogue.json']))
        ->assertRedirect(route('login'));

    $this->get(route('warehouse-lab.orders.index'))
        ->assertRedirect(route('login'));

    $this->get(route('warehouse-lab.orders.show', 44))
        ->assertRedirect(route('login'));
});

test('a signed-in user can open the warehouse lab', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('warehouse-lab'))
        ->assertOk()
        ->assertSee('Warehouse Lab')
        ->assertSee('fixed inset-0 top-14 lg:top-0 lg:left-64', false)
        ->assertSee(route('warehouse-lab.files', ['path' => 'index.html']), false);
});

test('a signed-in user can load the warehouse lab files', function () {
    $this->actingAs(User::factory()->create());

    $page = $this->get(route('warehouse-lab.files', ['path' => 'index.html']));

    $page->assertOk()
        ->assertHeader('content-type', 'text/html; charset=UTF-8');

    expect($page->streamedContent())
        ->toContain('Warehouse Lab')
        ->toContain('id="ordersearch"')
        ->toContain('./app.js');

    $script = $this->get(route('warehouse-lab.files', ['path' => 'app.js']));
    $script->assertOk()->assertHeader('content-type', 'text/javascript; charset=UTF-8');
    expect($script->streamedContent())->toContain('three')
        ->toContain('/warehouse-lab/orders');

    $vendor = $this->get(route('warehouse-lab.files', ['path' => 'vendor/three.module.js']));
    $vendor->assertOk()->assertHeader('content-type', 'text/javascript; charset=UTF-8');
    expect($vendor->streamedContent())->not->toBe('');

    $catalogue = $this->get(route('warehouse-lab.files', ['path' => 'location-catalogue.json']));
    $catalogue->assertOk()->assertHeader('content-type', 'application/json; charset=UTF-8');
    expect($catalogue->streamedContent())->toStartWith('{');
});

test('warehouse lab files cannot escape the prototype folder', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('warehouse-lab.files', ['path' => '../.env']))
        ->assertNotFound();

    $this->get(route('warehouse-lab.files', ['path' => 'vendor/../../.env']))
        ->assertNotFound();

    $this->get(route('warehouse-lab.files', ['path' => 'missing.html']))
        ->assertNotFound();
});

test('warehouse orders use the dene connection and ignore midmeds', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    Http::preventStrayRequests();

    $this->get(route('warehouse-lab.orders.index'))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Connect the Dene Healthcare Odoo connection before loading orders. The MidMeds connection is not used here.');

    Http::assertNothingSent();
});

test('a signed-in user can search recent dene orders', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->dene()->create(['url' => 'https://dene.odoo.com']);
    Http::preventStrayRequests();
    Http::fake([
        'https://dene.odoo.com/jsonrpc' => warehouseOdoo(...),
    ]);

    $this->get(route('warehouse-lab.orders.index', ['q' => 'Acme']))
        ->assertOk()
        ->assertJsonPath('orders.0.name', 'S00044')
        ->assertJsonPath('orders.0.customer', 'Acme Ltd')
        ->assertJsonPath('orders.0.ordered_on', '01/10/2026');

    Http::assertSent(function (Request $request): bool {
        $call = $request->data()['params']['args'] ?? [];

        return $request->url() === 'https://dene.odoo.com/jsonrpc'
            && ($call[3] ?? null) === 'sale.order'
            && str_contains((string) json_encode($call[5][0] ?? []), 'Acme');
    });
});

test('selecting an order builds a picking run from product locations', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->dene()->create(['url' => 'https://dene.odoo.com']);
    Http::preventStrayRequests();
    Http::fake([
        'https://dene.odoo.com/jsonrpc' => warehouseOdoo(...),
    ]);

    $this->get(route('warehouse-lab.orders.show', 44))
        ->assertOk()
        ->assertJsonPath('name', 'S00044')
        ->assertJsonPath('picks.0.product', 'Couch roll')
        ->assertJsonPath('picks.0.sku', 'COUCH-1')
        ->assertJsonPath('picks.0.quantity', 2)
        ->assertJsonPath('picks.0.location', 'WH/Stock/6L:09:RA:02:01')
        ->assertJsonPath('missing.0.product', 'Examination gloves')
        ->assertJsonMissingPath('picks.1');
});

test('a missing dene order is not simulated', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->dene()->create(['url' => 'https://dene.odoo.com']);
    Http::preventStrayRequests();
    Http::fake([
        'https://dene.odoo.com/jsonrpc' => warehouseOdoo(...),
    ]);

    $this->get(route('warehouse-lab.orders.show', 99))
        ->assertNotFound()
        ->assertJsonPath('message', 'That order was not found.');
});

test('an odoo failure does not expose the connection error', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->dene()->create(['url' => 'https://dene.odoo.com']);
    Http::preventStrayRequests();
    Http::fake([
        'https://dene.odoo.com/jsonrpc' => Http::response(['error' => ['message' => 'secret database detail']]),
    ]);

    $this->get(route('warehouse-lab.orders.index'))
        ->assertStatus(502)
        ->assertJsonPath('message', 'Could not read Dene Healthcare orders from Odoo.')
        ->assertDontSee('secret database detail');
});

function warehouseOdoo(Request $request): PromiseInterface
{
    $params = $request->data()['params'] ?? [];

    if (($params['method'] ?? null) === 'authenticate') {
        return Http::response(['result' => 7]);
    }

    $call = $params['args'] ?? [];
    $model = $call[3] ?? null;

    if ($model === 'sale.order') {
        $domain = $call[5][0] ?? [];
        $id = collect($domain)->first(fn (mixed $term): bool => is_array($term) && ($term[0] ?? null) === 'id')[2] ?? null;

        if ($id === 99) {
            return Http::response(['result' => []]);
        }

        return Http::response(['result' => [[
            'id' => 44,
            'name' => 'S00044',
            'date_order' => '2026-10-01 09:00:00',
            'partner_id' => [8, 'Acme Ltd'],
            'order_line' => [101, 102, 103],
            'state' => 'sale',
        ]]]);
    }

    if ($model === 'sale.order.line') {
        return Http::response(['result' => [
            [
                'id' => 101,
                'name' => 'Couch roll',
                'product_uom_qty' => 2,
                'product_id' => [50, 'Couch roll'],
                'display_type' => false,
            ],
            [
                'id' => 102,
                'name' => 'Examination gloves',
                'product_uom_qty' => 1,
                'product_id' => [51, 'Examination gloves'],
                'display_type' => false,
            ],
            [
                'id' => 103,
                'name' => 'Notes',
                'product_uom_qty' => 0,
                'product_id' => false,
                'display_type' => 'line_section',
            ],
        ]]);
    }

    if ($model === 'product.product') {
        return Http::response(['result' => [
            ['id' => 50, 'name' => 'Couch roll', 'default_code' => 'COUCH-1'],
            ['id' => 51, 'name' => 'Examination gloves', 'default_code' => 'GLV-1'],
        ]]);
    }

    if ($model === 'stock.quant') {
        return Http::response(['result' => [[
            'product_id' => [50, 'Couch roll'],
            'location_id' => [9, 'WH/Stock/6L:09:RA:02:01'],
            'quantity' => 12,
        ]]]);
    }

    return Http::response(['result' => []]);
}
