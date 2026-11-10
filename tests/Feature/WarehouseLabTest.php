<?php

use App\Models\OdooConnection;
use App\Models\User;
use App\Services\WarehouseMovePlanner;
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

    $this->get(route('warehouse-lab.moves'))
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
        ->toContain('id="movefrom"')
        ->toContain('./app.js');

    $script = $this->get(route('warehouse-lab.files', ['path' => 'app.js']));
    $script->assertOk()->assertHeader('content-type', 'text/javascript; charset=UTF-8');
    expect($script->streamedContent())->toContain('three')
        ->toContain('/warehouse-lab/orders')
        ->toContain('/warehouse-lab/moves');

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

test('stock move suggestions keep pharma in the drug rooms and free the occupied bin', function () {
    $result = app(WarehouseMovePlanner::class)->suggest([
        ['product' => 'Couch roll', 'sku' => 'COUCH-1', 'quantity' => 10, 'location' => 'WH/Stock/9L:08:RM:03:01', 'pharma' => false],
        ['product' => 'Examination gloves', 'sku' => 'GLV-1', 'quantity' => 1, 'location' => 'WH/Stock/4L:01:RA:01:01', 'pharma' => false],
        ['product' => 'Cold chain vaccine', 'sku' => 'VAC-1', 'quantity' => 8, 'location' => 'WH/Stock/7L:10:AA:01:01', 'pharma' => true],
        ['product' => 'Amoxicillin', 'sku' => 'AMOX-1', 'quantity' => 20, 'location' => 'WH/Stock/8L:12:RM:03:01', 'pharma' => true],
        ['product' => 'Shelf trolley', 'sku' => 'TROL-1', 'quantity' => 1, 'location' => 'WH/Stock/7L:11:AA:01:01', 'pharma' => false],
    ]);

    expect($result['pharma_left'])->toBe(3)
        ->and($result['suggestions'])->toHaveCount(1)
        ->and($result['suggestions'][0]['product'])->toBe('Couch roll')
        ->and($result['suggestions'][0]['to'])->toBe('WH/Stock/4L:01:RA:01:01')
        ->and($result['suggestions'][0]['displaced']['product'])->toBe('Examination gloves')
        ->and($result['suggestions'][0]['displaced']['to'])->toBe('WH/Stock/9L:08:RM:03:01');
});

test('move suggestions refuse a date range that ends first', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $this->get(route('warehouse-lab.moves', ['from' => '2026-11-10', 'to' => '2026-10-01']))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The end date must be on or after the start date.');

    Http::assertNothingSent();
});

test('move suggestions come from dene orders and leave pharma stock where it is', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->dene()->create(['url' => 'https://dene.odoo.com']);
    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    Http::preventStrayRequests();
    Http::fake([
        'https://dene.odoo.com/jsonrpc' => moveOdoo(...),
    ]);

    $this->get(route('warehouse-lab.moves', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertOk()
        ->assertJsonPath('order_count', 1)
        ->assertJsonPath('suggestions.0.product', 'Couch roll')
        ->assertJsonPath('suggestions.0.displaced.product', 'Examination gloves')
        ->assertJsonPath('suggestions.0.displaced.to', 'WH/Stock/9L:08:RM:03:01')
        ->assertJsonPath('picks.2.pharma', true);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'midmeds.odoo.com'));
});

function moveOdoo(Request $request): PromiseInterface
{
    $params = $request->data()['params'] ?? [];

    if (($params['method'] ?? null) === 'authenticate') {
        return Http::response(['result' => 7]);
    }

    $model = $params['args'][3] ?? null;

    if ($model === 'sale.order') {
        return Http::response(['result' => [[
            'id' => 70,
            'name' => 'S00070',
            'date_order' => '2026-10-15 09:00:00',
            'order_line' => [201, 202, 203, 204],
        ]]]);
    }

    if ($model === 'sale.order.line') {
        return Http::response(['result' => [
            ['id' => 201, 'name' => 'Couch roll', 'product_uom_qty' => 10, 'product_id' => [50, 'Couch roll'], 'order_id' => [70, 'S00070'], 'display_type' => false],
            ['id' => 202, 'name' => 'Examination gloves', 'product_uom_qty' => 1, 'product_id' => [51, 'Examination gloves'], 'order_id' => [70, 'S00070'], 'display_type' => false],
            ['id' => 203, 'name' => 'Cold chain vaccine', 'product_uom_qty' => 8, 'product_id' => [52, 'Cold chain vaccine'], 'order_id' => [70, 'S00070'], 'display_type' => false],
            ['id' => 204, 'name' => 'Amoxicillin', 'product_uom_qty' => 20, 'product_id' => [53, 'Amoxicillin'], 'order_id' => [70, 'S00070'], 'display_type' => false],
        ]]);
    }

    if ($model === 'product.product') {
        return Http::response(['result' => [
            ['id' => 50, 'name' => 'Couch roll', 'default_code' => 'COUCH-1', 'categ_id' => [1, 'Consumables']],
            ['id' => 51, 'name' => 'Examination gloves', 'default_code' => 'GLV-1', 'categ_id' => [1, 'Consumables']],
            ['id' => 52, 'name' => 'Cold chain vaccine', 'default_code' => 'VAC-1', 'categ_id' => [2, 'Pharma']],
            ['id' => 53, 'name' => 'Amoxicillin', 'default_code' => 'AMOX-1', 'categ_id' => [2, 'Pharma']],
        ]]);
    }

    if ($model === 'stock.quant') {
        return Http::response(['result' => [
            ['product_id' => [50, 'Couch roll'], 'location_id' => [9, 'WH/Stock/9L:08:RM:03:01'], 'quantity' => 12],
            ['product_id' => [51, 'Examination gloves'], 'location_id' => [10, 'WH/Stock/4L:01:RA:01:01'], 'quantity' => 4],
            ['product_id' => [52, 'Cold chain vaccine'], 'location_id' => [11, 'WH/Stock/7L:10:AA:01:01'], 'quantity' => 6],
            ['product_id' => [53, 'Amoxicillin'], 'location_id' => [12, 'WH/Stock/8L:12:RM:03:01'], 'quantity' => 9],
        ]]);
    }

    return Http::response(['result' => []]);
}

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
