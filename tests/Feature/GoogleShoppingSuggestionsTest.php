<?php

use App\Enums\CustomerReviewStatus;
use App\Models\AiModel;
use App\Models\GoogleShoppingScan;
use App\Models\GoogleShoppingSuggestion;
use App\Models\OdooConnection;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('guests are redirected to the login page', function () {
    $this->get(route('google-shopping-suggestions'))
        ->assertRedirect(route('login'));
});

test('the page explains when no products have been found', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('google-shopping-suggestions'))
        ->assertOk()
        ->assertSee('Google Shopping Suggestions')
        ->assertSee('No products have been found here yet.')
        ->assertDontSee('Check now');
});

test('sales and cost prices are compared with the cheapest google shopping price including vat', function () {
    $suggestion = GoogleShoppingSuggestion::factory()->make([
        'sales_price' => '10.00',
        'cost_price' => '4.00',
    ]);

    $suggestion->applyCheapestPrice(12.50);

    expect($suggestion->beats_sales_price)->toBeTrue()
        ->and($suggestion->salesSaving())->toBe(0.5)
        ->and($suggestion->beats_cost_price)->toBeTrue()
        ->and($suggestion->potential_margin)->toBe('6.41')
        ->and($suggestion->potential_margin_percent)->toBe('61.57');

    $suggestion->applyCheapestPrice(12.00);

    expect($suggestion->beats_sales_price)->toBeFalse()
        ->and($suggestion->beats_cost_price)->toBeTrue();

    $suggestion->applyCheapestPrice(4.80);

    expect($suggestion->beats_cost_price)->toBeFalse()
        ->and((float) $suggestion->potential_margin)->toBeLessThan(0);

    $suggestion->cost_price = null;
    $suggestion->applyCheapestPrice(20.00);

    expect($suggestion->beats_cost_price)->toBeFalse()
        ->and($suggestion->potential_margin)->toBeNull();

    $suggestion->applyCheapestPrice(null);

    expect($suggestion->beats_sales_price)->toBeFalse()
        ->and($suggestion->cheapest_price)->toBeNull();
});

test('best sellers are priced against google shopping and split by sales and cost price', function () {
    $this->actingAs(User::factory()->admin()->create());
    OdooConnection::factory()->create(['url' => 'https://acme.odoo.com']);
    AiModel::factory()->create();

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://api.openai.com/v1/responses') {
            $input = $request->data()['input'] ?? '';

            $price = match (true) {
                str_contains($input, 'Nitrile Gloves') => ['found' => true, 'cheapest_price_gbp' => 7.20, 'seller' => 'Medisave'],
                str_contains($input, 'Face Masks') => ['found' => true, 'cheapest_price_gbp' => '£9.00', 'seller' => 'Mask Shop'],
                str_contains($input, 'Gold Plasters') => ['found' => true, 'cheapest_price_gbp' => 9.00, 'seller' => 'Cheap Plasters'],
                default => ['found' => false, 'cheapest_price_gbp' => null, 'seller' => null],
            };

            return Http::response([
                'output_text' => json_encode($price + [
                    'url' => 'https://shop.example/listing',
                    'matched_product' => 'Matched listing',
                    'notes' => 'Same pack size.',
                ]),
            ]);
        }

        return googleShoppingOdooResponse($request);
    });

    Livewire::test('pages::google-shopping-suggestions')
        ->call('checkNow')
        ->assertSee('Nitrile Gloves')
        ->assertSee('£6.00')
        ->assertSee('£7.20')
        ->assertSee('£1.20')
        ->assertDontSee('Face Masks')
        ->assertDontSee('Gold Plasters')
        ->set('view', 'cost')
        ->assertSeeInOrder(['Nitrile Gloves', 'Face Masks'])
        ->assertSee('£8.99')
        ->assertSee('£3.49')
        ->assertSee('46.6%')
        ->assertDontSee('Gold Plasters')
        ->set('view', 'all')
        ->assertSee('Gold Plasters')
        ->assertSee('Cannot beat')
        ->assertSee('Rare Bandage')
        ->assertSee('No match found');

    expect(GoogleShoppingSuggestion::query()->count())->toBe(4)
        ->and(GoogleShoppingSuggestion::query()->where('product_name', 'Rare Bandage')->value('status'))->toBe(CustomerReviewStatus::Complete)
        ->and(GoogleShoppingScan::current()->next_rank)->toBe(4);

    $rankings = collect(Http::recorded())
        ->map(fn (array $pair): array => $pair[0]->data()['params']['args'] ?? [])
        ->filter(fn (array $args): bool => ($args[4] ?? null) === 'read_group');

    expect($rankings)->toHaveCount(1)
        ->and((array) $rankings->first()[6])->toMatchArray([
            'orderby' => 'product_uom_qty desc',
            'limit' => 20,
            'offset' => 0,
        ]);
});

test('the check starts again from the best seller once every product has been checked', function () {
    OdooConnection::factory()->create();
    AiModel::factory()->create();
    GoogleShoppingScan::current()->forceFill(['next_rank' => 40])->save();

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://api.openai.com/v1/responses') {
            return Http::response(['output_text' => json_encode(['found' => false])]);
        }

        return googleShoppingOdooResponse($request);
    });

    $this->artisan('google-shopping-suggestions:check')
        ->expectsOutputToContain('Checked 4 products.')
        ->assertSuccessful();

    expect(GoogleShoppingScan::current()->next_rank)->toBe(4);
});

test('a product is marked as failed when chatgpt cannot price it', function () {
    OdooConnection::factory()->create();
    AiModel::factory()->create();

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://api.openai.com/v1/responses') {
            return Http::response(['error' => 'busy'], 500);
        }

        return googleShoppingOdooResponse($request);
    });

    $this->artisan('google-shopping-suggestions:check')->assertSuccessful();

    expect(GoogleShoppingSuggestion::query()->where('status', CustomerReviewStatus::Failed)->count())->toBe(4)
        ->and(GoogleShoppingSuggestion::query()->where('beats_sales_price', true)->count())->toBe(0);
});

test('the page escapes product details', function () {
    $this->actingAs(User::factory()->create());

    GoogleShoppingSuggestion::factory()->create([
        'product_name' => '<script>alert(1)</script>',
    ]);

    $this->get(route('google-shopping-suggestions'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('non-admins cannot start a google shopping check', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::google-shopping-suggestions')
        ->call('checkNow')
        ->assertForbidden();

    Http::assertNothingSent();
});

test('the check tells the user when odoo is not connected', function () {
    $this->artisan('google-shopping-suggestions:check')
        ->expectsOutputToContain('Connect Odoo before checking Google Shopping prices.')
        ->assertFailed();

    Http::assertNothingSent();
});

test('best sellers are checked every hour', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'google-shopping-suggestions:check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

function googleShoppingOdooResponse(Request $request): PromiseInterface
{
    $params = $request->data()['params'] ?? [];

    if (($params['method'] ?? null) === 'authenticate') {
        return Http::response(['result' => 7]);
    }

    $call = $params['args'] ?? [];
    $model = $call[3] ?? null;
    $method = $call[4] ?? null;

    if ($model === 'sale.order.line' && $method === 'read_group') {
        if ((((array) ($call[6] ?? []))['offset'] ?? 0) > 0) {
            return Http::response(['result' => []]);
        }

        return Http::response(['result' => [
            ['product_id' => [1, 'Nitrile Gloves'], 'product_uom_qty' => 900],
            ['product_id' => [2, 'Face Masks'], 'product_uom_qty' => 400],
            ['product_id' => [3, 'Gold Plasters'], 'product_uom_qty' => 150],
            ['product_id' => [4, 'Rare Bandage'], 'product_uom_qty' => 12],
        ]]);
    }

    if ($model === 'product.product' && $method === 'read') {
        return Http::response(['result' => [
            ['id' => 1, 'name' => 'Nitrile Gloves', 'default_code' => 'GLV-1', 'barcode' => false, 'lst_price' => 5.00, 'standard_price' => 3.00],
            ['id' => 2, 'name' => 'Face Masks', 'default_code' => 'MSK-1', 'barcode' => '5012345678900', 'lst_price' => 10.00, 'standard_price' => 4.00],
            ['id' => 3, 'name' => 'Gold Plasters', 'default_code' => false, 'barcode' => false, 'lst_price' => 12.00, 'standard_price' => 10.00],
            ['id' => 4, 'name' => 'Rare Bandage', 'default_code' => false, 'barcode' => false, 'lst_price' => 3.00, 'standard_price' => 0],
        ]]);
    }

    return Http::response(['result' => []]);
}
