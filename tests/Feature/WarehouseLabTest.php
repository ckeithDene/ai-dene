<?php

use App\Models\AiModel;
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
        ->toContain('id="moveai"')
        ->toContain('id="heatlegend"')
        ->toContain('id="loctip"')
        ->toContain('./app.js');

    $script = $this->get(route('warehouse-lab.files', ['path' => 'app.js']));
    $script->assertOk()->assertHeader('content-type', 'text/javascript; charset=UTF-8');
    expect($script->streamedContent())->toContain('three')
        ->toContain('/warehouse-lab/orders')
        ->toContain('/warehouse-lab/moves')
        ->toContain('moveai')
        ->toContain('showHeatmap')
        ->toContain('heatRank')
        ->toContain('showLocationTip');

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
        ['product' => 'Returns tote', 'sku' => 'RET-1', 'quantity' => 30, 'location' => 'WH/Stock/PACKING ZONE', 'pharma' => false],
    ]);

    expect($result['pharma_left'])->toBe(3)
        ->and($result['suggestions'])->toHaveCount(1)
        ->and($result['suggestions'][0]['product'])->toBe('Couch roll')
        ->and($result['suggestions'][0]['to'])->toBe('WH/Stock/4L:01:RA:01:01')
        ->and($result['suggestions'][0]['displaced']['product'])->toBe('Examination gloves')
        ->and($result['suggestions'][0]['displaced']['to'])->toBe('WH/Stock/9L:08:RM:03:01')
        ->and($result['suggestions'][0]['follows'])->toBe([]);
});

test('stock moves in one plan leave the displaced product in a location that plan frees', function () {
    $result = app(WarehouseMovePlanner::class)->suggest([
        ['product' => 'Couch roll', 'sku' => 'COUCH-1', 'quantity' => 10, 'location' => 'WH/Stock/5L:06:RC:01:01', 'pharma' => false],
        ['product' => 'Wipes', 'sku' => 'WIPE-1', 'quantity' => 5, 'location' => 'WH/Stock/9L:08:RM:03:01', 'pharma' => false],
        ['product' => 'Tape', 'sku' => 'TAPE-1', 'quantity' => 1, 'location' => 'WH/Stock/4L:01:RA:01:01', 'pharma' => false],
    ]);

    $suggestion = $result['suggestions'][0];
    $placed = [
        $suggestion['product'] => $suggestion['to'],
        $suggestion['displaced']['product'] => $suggestion['displaced']['to'],
    ];

    foreach ($suggestion['follows'] as $step) {
        $placed[$step['product']] = $step['to'];
    }

    expect($result['suggestions'])->toHaveCount(1)
        ->and($placed['Couch roll'])->toBe('WH/Stock/4L:01:RA:01:01')
        ->and($placed['Wipes'])->toBe('WH/Stock/5L:06:RC:01:01')
        ->and($placed['Tape'])->toBe('WH/Stock/9L:08:RM:03:01')
        ->and(array_values($placed))->toBe(array_values(array_unique($placed)));
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
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses');
});

test('move suggestions read every order in the date range', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->dene()->create(['url' => 'https://dene.odoo.com']);
    Http::preventStrayRequests();
    Http::fake([
        'https://dene.odoo.com/jsonrpc' => pagedMoveOdoo(...),
    ]);

    $this->get(route('warehouse-lab.moves', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertOk()
        ->assertJsonPath('order_count', 81)
        ->assertJsonPath('truncated', false)
        ->assertJsonPath('suggestions.0.product', 'Couch roll');
});

test('ai assistance needs the saved chatgpt connection', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $this->get(route('warehouse-lab.moves', ['from' => '2026-10-01', 'to' => '2026-10-31', 'ai' => 1]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Connect ChatGPT before asking for location advice.');

    Http::assertNothingSent();
});

test('ai assistance asks chatgpt to rearrange stock and returns picking notes', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->dene()->create(['url' => 'https://dene.odoo.com']);
    AiModel::factory()->create();
    Http::preventStrayRequests();
    Http::fake([
        'https://dene.odoo.com/jsonrpc' => moveOdoo(...),
        'https://api.openai.com/v1/responses' => Http::response([
            'output_text' => json_encode([
                'moves' => [
                    ['product' => 'Couch roll', 'sku' => 'COUCH-1', 'from' => '9L:08:RM:03:01', 'to' => '4L:01:RA:01:01'],
                    ['product' => 'Examination gloves', 'sku' => 'GLV-1', 'from' => '4L:01:RA:01:01', 'to' => '9L:08:RM:03:01'],
                ],
                'notes' => ['Pick the couch rolls on the way back from the close aisle.'],
            ]),
        ]),
    ]);

    $this->get(route('warehouse-lab.moves', ['from' => '2026-10-01', 'to' => '2026-10-31', 'ai' => 1]))
        ->assertOk()
        ->assertJsonPath('suggestions.0.product', 'Couch roll')
        ->assertJsonPath('suggestions.0.displaced.product', 'Examination gloves')
        ->assertJsonPath('notes.0', 'Pick the couch rolls on the way back from the close aisle.')
        ->assertJsonPath('ai_warning', null)
        ->assertDontSee('test-api-key');

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://api.openai.com/v1/responses') {
            return false;
        }

        $body = $request->data();
        $instructions = $body['instructions'] ?? '';
        $input = $body['input'] ?? '';

        return is_string($instructions)
            && str_contains($instructions, 'PACKING ZONE')
            && str_contains($instructions, '7L')
            && is_string($input)
            && str_contains($input, '4L:01:RA:01:01')
            && ! array_key_exists('tools', $body);
    });
});

test('ai assistance keeps the calculated moves when chatgpt uses a drug room', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->dene()->create(['url' => 'https://dene.odoo.com']);
    AiModel::factory()->create();
    Http::preventStrayRequests();
    Http::fake([
        'https://dene.odoo.com/jsonrpc' => moveOdoo(...),
        'https://api.openai.com/v1/responses' => Http::response([
            'output_text' => json_encode([
                'moves' => [
                    ['product' => 'Couch roll', 'sku' => 'COUCH-1', 'from' => '9L:08:RM:03:01', 'to' => '7L:10:AA:01:01'],
                ],
                'notes' => ['Leave the vaccines in the drug room.'],
            ]),
        ]),
    ]);

    $this->get(route('warehouse-lab.moves', ['from' => '2026-10-01', 'to' => '2026-10-31', 'ai' => 1]))
        ->assertOk()
        ->assertJsonPath('suggestions.0.to', 'WH/Stock/4L:01:RA:01:01')
        ->assertJsonPath('notes.0', 'Leave the vaccines in the drug room.')
        ->assertJsonPath('ai_warning', 'ChatGPT suggested moves that would not leave a free location, so these are the calculated suggestions.');
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
            ['product_id' => [50, 'Couch roll'], 'location_id' => [90, 'WH/Stock/PACKING ZONE'], 'quantity' => 100],
            ['product_id' => [50, 'Couch roll'], 'location_id' => [9, 'WH/Stock/9L:08:RM:03:01'], 'quantity' => 12],
            ['product_id' => [51, 'Examination gloves'], 'location_id' => [10, 'WH/Stock/4L:01:RA:01:01'], 'quantity' => 4],
            ['product_id' => [52, 'Cold chain vaccine'], 'location_id' => [11, 'WH/Stock/7L:10:AA:01:01'], 'quantity' => 6],
            ['product_id' => [53, 'Amoxicillin'], 'location_id' => [12, 'WH/Stock/8L:12:RM:03:01'], 'quantity' => 9],
        ]]);
    }

    return Http::response(['result' => []]);
}

function pagedMoveOdoo(Request $request): PromiseInterface
{
    $params = $request->data()['params'] ?? [];

    if (($params['method'] ?? null) === 'authenticate') {
        return Http::response(['result' => 7]);
    }

    if (($params['args'][3] ?? null) === 'sale.order') {
        $offset = (int) data_get($params, 'args.6.offset', 0);

        if ($offset === 0) {
            $orders = [];

            for ($i = 1; $i <= 80; $i++) {
                $orders[] = [
                    'id' => $i,
                    'name' => 'S'.$i,
                    'date_order' => '2026-10-02 09:00:00',
                    'order_line' => [],
                ];
            }

            return Http::response(['result' => $orders]);
        }

        return Http::response(['result' => [[
            'id' => 81,
            'name' => 'S00081',
            'date_order' => '2026-10-15 09:00:00',
            'order_line' => [201, 202, 203, 204],
        ]]]);
    }

    return moveOdoo($request);
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
