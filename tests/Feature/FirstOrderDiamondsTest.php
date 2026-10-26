<?php

use App\Actions\ReviewFirstOrderDiamonds;
use App\Enums\FirstOrderDiamondStatus;
use App\Models\AiModel;
use App\Models\FirstOrderDiamond;
use App\Models\OdooConnection;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('guests are redirected to the login page', function () {
    $this->get(route('first-order-diamonds'))
        ->assertRedirect(route('login'));
});

test('authenticated users can view reviewed first orders', function () {
    $user = User::factory()->create();

    FirstOrderDiamond::factory()->create([
        'company_name' => 'Acme Ltd',
        'overview' => 'A strong first-order lead.',
    ]);

    $this->actingAs($user)
        ->get(route('first-order-diamonds'))
        ->assertOk()
        ->assertSee('First Order Diamonds')
        ->assertSee('Acme Ltd')
        ->assertSee('A strong first-order lead.')
        ->assertDontSee('Check now');
});

test('the first order page escapes company details', function () {
    $this->actingAs(User::factory()->create());

    FirstOrderDiamond::factory()->create([
        'company_name' => '<script>alert(1)</script>',
    ]);

    $this->get(route('first-order-diamonds'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('the page explains when no first orders have been reviewed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('first-order-diamonds'))
        ->assertOk()
        ->assertSee('No first orders have been reviewed yet.');
});

test('non-admins cannot start a first order check', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::first-order-diamonds')
        ->call('checkNow')
        ->assertForbidden();

    Http::assertNothingSent();
});

test('admins can review a confirmed first order', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->admin()->create());
    OdooConnection::factory()->create();
    AiModel::factory()->create([
        'model' => 'gpt-4.1',
        'organization' => 'org-acme',
    ]);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    Http::fake(function (Request $request) use ($png) {
        if ($request->url() === 'https://api.openai.com/v1/responses') {
            return Http::response([
                'output_text' => json_encode([
                    'overview' => 'Acme looks like a strong regional manufacturer.',
                    'company_size' => '200 employees',
                    'estimated_value' => '£40 million',
                    'market_share' => 'Small regional share',
                    'logo_url' => null,
                ]),
            ]);
        }

        $params = $request->data()['params'] ?? [];

        if (($params['method'] ?? null) === 'authenticate') {
            return Http::response(['result' => 7]);
        }

        $call = $params['args'] ?? [];
        $model = $call[3] ?? null;

        if ($model === 'sale.order') {
            return Http::response(firstOrderSaleOrders($call, [
                [
                    'id' => 10,
                    'name' => 'S00010',
                    'date_order' => '2026-10-01 10:00:00',
                    'partner_id' => [9, 'Jane Smith'],
                    'state' => 'sale',
                ],
                [
                    'id' => 11,
                    'name' => 'S00011',
                    'date_order' => '2026-10-15 10:00:00',
                    'partner_id' => [9, 'Jane Smith'],
                    'state' => 'sale',
                ],
            ]));
        }

        $ids = $call[5][0] ?? [];

        if ($ids === [9]) {
            return Http::response(['result' => [[
                'id' => 9,
                'name' => 'Jane Smith',
                'street' => false,
                'street2' => false,
                'city' => false,
                'zip' => false,
                'country_id' => false,
                'commercial_partner_id' => [5, 'Acme Ltd'],
                'image_512' => false,
            ]]]);
        }

        return Http::response(['result' => [[
            'id' => 5,
            'name' => 'Acme Ltd',
            'street' => '1 High Street',
            'street2' => false,
            'city' => 'London',
            'zip' => 'SW1A 1AA',
            'country_id' => [1, 'United Kingdom'],
            'commercial_partner_id' => [5, 'Acme Ltd'],
            'image_512' => base64_encode($png),
        ]]]);
    });

    Livewire::test('pages::first-order-diamonds')
        ->call('checkNow')
        ->assertSee('Acme Ltd')
        ->assertSee('Acme looks like a strong regional manufacturer.')
        ->assertSee('200 employees');

    $diamond = FirstOrderDiamond::query()->sole();

    expect($diamond->odoo_partner_id)->toBe(5)
        ->and($diamond->order_name)->toBe('S00010')
        ->and($diamond->address)->toBe('1 High Street, London, SW1A 1AA, United Kingdom')
        ->and($diamond->status)->toBe(FirstOrderDiamondStatus::Complete)
        ->and($diamond->logo_path)->toBe('first-order-diamonds/5.png');

    Storage::disk('public')->assertExists('first-order-diamonds/5.png');

    Http::assertSent(function (Request $request) {
        if ($request->url() !== 'https://api.openai.com/v1/responses') {
            return false;
        }

        return $request->data()['model'] === 'gpt-4.1'
            && $request->data()['tools'][0]['type'] === 'web_search'
            && str_contains($request->data()['input'], 'Acme Ltd')
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request->hasHeader('OpenAI-Organization', 'org-acme');
    });
});

test('a completed first order is not reviewed again', function () {
    OdooConnection::factory()->create();
    AiModel::factory()->create();

    FirstOrderDiamond::factory()->create([
        'odoo_partner_id' => 5,
        'status' => FirstOrderDiamondStatus::Complete,
    ]);

    Http::fake(function (Request $request) {
        $params = $request->data()['params'] ?? [];

        if (($params['method'] ?? null) === 'authenticate') {
            return Http::response(['result' => 7]);
        }

        $model = $params['args'][3] ?? null;

        if ($model === 'sale.order') {
            return Http::response(firstOrderSaleOrders($params['args'], [[
                'id' => 10,
                'name' => 'S00010',
                'date_order' => '2026-10-02 10:00:00',
                'partner_id' => [5, 'Acme Ltd'],
                'state' => 'sale',
            ]]));
        }

        return Http::response(['result' => [[
            'id' => 5,
            'name' => 'Acme Ltd',
            'street' => '1 High Street',
            'street2' => false,
            'city' => 'London',
            'zip' => false,
            'country_id' => false,
            'commercial_partner_id' => [5, 'Acme Ltd'],
            'image_512' => false,
        ]]]);
    });

    $result = app(ReviewFirstOrderDiamonds::class)->handle();

    expect($result->reviewed)->toBe(0);

    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/responses');
});

test('a failed chatgpt review is not reviewed again', function () {
    OdooConnection::factory()->create();
    AiModel::factory()->create(['model' => 'gpt-4.1']);

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://api.openai.com/v1/responses') {
            return Http::response(['error' => ['message' => 'server_error']], 500);
        }

        $params = $request->data()['params'] ?? [];

        if (($params['method'] ?? null) === 'authenticate') {
            return Http::response(['result' => 7]);
        }

        $model = $params['args'][3] ?? null;

        if ($model === 'sale.order') {
            return Http::response(firstOrderSaleOrders($params['args'], [[
                'id' => 10,
                'name' => 'S00010',
                'date_order' => '2026-10-02 10:00:00',
                'partner_id' => [5, 'Acme Ltd'],
                'state' => 'sale',
            ]]));
        }

        return Http::response(['result' => [[
            'id' => 5,
            'name' => 'Acme Ltd',
            'street' => false,
            'street2' => false,
            'city' => false,
            'zip' => false,
            'country_id' => false,
            'commercial_partner_id' => [5, 'Acme Ltd'],
            'image_512' => false,
        ]]]);
    });

    $this->artisan('first-order-diamonds:check')
        ->expectsOutputToContain('Reviewed 1 first order.')
        ->assertSuccessful();

    expect(FirstOrderDiamond::query()->sole()->status)->toBe(FirstOrderDiamondStatus::Failed)
        ->and(FirstOrderDiamond::query()->sole()->failure_reason)->toBe('ChatGPT could not review this company.');

    $result = app(ReviewFirstOrderDiamonds::class)->handle();

    expect($result->reviewed)->toBe(0)
        ->and(FirstOrderDiamond::query()->count())->toBe(1);

    $reviews = collect(Http::recorded())
        ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://api.openai.com/v1/responses')
        ->count();

    expect($reviews)->toBe(1);
});

test('orders before 1 October 2026 are not reviewed', function () {
    OdooConnection::factory()->create();
    AiModel::factory()->create();

    Http::fake(function (Request $request) {
        $params = $request->data()['params'] ?? [];

        if (($params['method'] ?? null) === 'authenticate') {
            return Http::response(['result' => 7]);
        }

        if (($params['args'][3] ?? null) === 'sale.order') {
            return Http::response(firstOrderSaleOrders($params['args'], [[
                'id' => 10,
                'name' => 'S00010',
                'date_order' => '2026-09-30 10:00:00',
                'partner_id' => [5, 'Acme Ltd'],
                'state' => 'sale',
            ]]));
        }

        return Http::response(['result' => []]);
    });

    $result = app(ReviewFirstOrderDiamonds::class)->handle();

    expect($result->reviewed)->toBe(0);
    expect(FirstOrderDiamond::query()->count())->toBe(0);

    Http::assertSent(function (Request $request) {
        $domain = $request->data()['params']['args'][5][0] ?? [];

        return is_array($domain) && collect($domain)->contains(
            fn (mixed $clause): bool => is_array($clause)
                && ($clause[0] ?? null) === 'date_order'
                && ($clause[1] ?? null) === '>='
                && ($clause[2] ?? null) === '2026-10-01 00:00:00',
        );
    });
    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/responses');
});

test('a company that ordered before 1 October 2026 is not a first order', function () {
    OdooConnection::factory()->create();
    AiModel::factory()->create();

    Http::fake(function (Request $request) {
        $params = $request->data()['params'] ?? [];

        if (($params['method'] ?? null) === 'authenticate') {
            return Http::response(['result' => 7]);
        }

        if (($params['args'][3] ?? null) === 'sale.order') {
            return Http::response(firstOrderSaleOrders($params['args'], [[
                'id' => 10,
                'name' => 'S00010',
                'date_order' => '2026-10-02 10:00:00',
                'partner_id' => [5, 'Acme Ltd'],
                'state' => 'sale',
            ]], [[
                'id' => 4,
            ]]));
        }

        return Http::response(['result' => [[
            'id' => 5,
            'name' => 'Acme Ltd',
            'street' => '1 High Street',
            'street2' => false,
            'city' => 'London',
            'zip' => false,
            'country_id' => false,
            'commercial_partner_id' => [5, 'Acme Ltd'],
            'image_512' => false,
        ]]]);
    });

    $result = app(ReviewFirstOrderDiamonds::class)->handle();

    expect($result->reviewed)->toBe(0);
    expect(FirstOrderDiamond::query()->count())->toBe(0);

    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/responses');
});

test('a private logo url is not downloaded', function () {
    Storage::fake('public');
    OdooConnection::factory()->create();
    AiModel::factory()->create();

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://api.openai.com/v1/responses') {
            return Http::response([
                'output_text' => json_encode([
                    'overview' => 'A useful lead.',
                    'company_size' => 'Unknown',
                    'estimated_value' => 'Unknown',
                    'market_share' => 'Unknown',
                    'logo_url' => 'https://127.0.0.1/logo.png',
                ]),
            ]);
        }

        $params = $request->data()['params'] ?? [];

        if (($params['method'] ?? null) === 'authenticate') {
            return Http::response(['result' => 7]);
        }

        if (($params['args'][3] ?? null) === 'sale.order') {
            return Http::response(firstOrderSaleOrders($params['args'], [[
                'id' => 10,
                'name' => 'S00010',
                'date_order' => '2026-10-02 10:00:00',
                'partner_id' => [5, 'Acme Ltd'],
                'state' => 'sale',
            ]]));
        }

        return Http::response(['result' => [[
            'id' => 5,
            'name' => 'Acme Ltd',
            'street' => false,
            'street2' => false,
            'city' => false,
            'zip' => false,
            'country_id' => false,
            'commercial_partner_id' => [5, 'Acme Ltd'],
            'image_512' => false,
        ]]]);
    });

    app(ReviewFirstOrderDiamonds::class)->handle();

    expect(FirstOrderDiamond::query()->sole()->logo_path)->toBeNull()
        ->and(FirstOrderDiamond::query()->sole()->overview)->toBe('A useful lead.');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '127.0.0.1'));
});

test('the check tells the user when odoo is not connected', function () {
    $this->artisan('first-order-diamonds:check')
        ->expectsOutputToContain('Connect Odoo before checking first orders.')
        ->assertFailed();

    Http::assertNothingSent();
});

test('new first orders are checked every hour', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'first-order-diamonds:check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

/**
 * Keep the order list for the review window, and the earlier-order lookup separate.
 *
 * @param  array<int, mixed>  $call
 * @param  array<int, array<string, mixed>>  $orders
 * @param  array<int, array<string, mixed>>  $earlierOrders
 * @return array<string, array<int, array<string, mixed>>>
 */
function firstOrderSaleOrders(array $call, array $orders, array $earlierOrders = []): array
{
    $domain = $call[5][0] ?? [];
    $isEarlierLookup = is_array($domain) && collect($domain)->contains(
        fn (mixed $clause): bool => is_array($clause) && ($clause[0] ?? null) === 'date_order' && ($clause[1] ?? null) === '<',
    );

    return ['result' => $isEarlierLookup ? $earlierOrders : $orders];
}
