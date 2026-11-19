<?php

use App\Enums\CustomerReviewStatus;
use App\Models\AiModel;
use App\Models\BiggestFish;
use App\Models\BiggestFishScan;
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
    $this->get(route('biggest-fish'))
        ->assertRedirect(route('login'));
});

test('the page explains when no large customers have been found', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('biggest-fish'))
        ->assertOk()
        ->assertSee('No customers over £20 million have been found yet.')
        ->assertSee('No scan has run yet.')
        ->assertDontSee('Check now');
});

test('the page shows when the latest scan ran', function () {
    $this->actingAs(User::factory()->create());

    $scan = new BiggestFishScan;
    $scan->last_order_id = 4521;
    $scan->save();

    $this->get(route('biggest-fish'))
        ->assertOk()
        ->assertSee('Last scan '.$scan->updated_at->timezone(config('app.timezone'))->format('j M Y, H:i'))
        ->assertSee('Reached order 4521');
});

test('biggest fish are listed by estimated value with the total spent', function () {
    $this->actingAs(User::factory()->admin()->create());
    OdooConnection::factory()->create(['url' => 'https://acme.odoo.com']);
    AiModel::factory()->create();

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://api.openai.com/v1/responses') {
            $input = $request->data()['input'] ?? '';
            $value = str_contains($input, 'Big Co') ? 80_000_000 : (str_contains($input, 'Small Ltd') ? 5_000_000 : 40_000_000);
            $label = $value === 80_000_000 ? '£80 million' : ($value === 5_000_000 ? '£5 million' : '£40 million');

            return Http::response([
                'output_text' => json_encode([
                    'is_registered_company' => true,
                    'overview' => 'A substantial business.',
                    'company_size' => '1,000 employees',
                    'estimated_value' => $label,
                    'estimated_value_gbp' => $value,
                    'market_share' => 'National',
                    'logo_url' => null,
                    'website' => null,
                ]),
            ]);
        }

        return biggestFishOdooResponse($request);
    });

    Livewire::test('pages::biggest-fish')
        ->call('checkNow')
        ->assertSee('Acme Ltd')
        ->assertSee('£40 million')
        ->assertSee('£4,000.00')
        ->assertDontSee('Small Ltd')
        ->call('checkNow')
        ->assertSeeInOrder(['Big Co', 'Acme Ltd'])
        ->assertSee('£80 million')
        ->assertSee('£20,000.00')
        ->assertDontSee('Small Ltd');

    expect(BiggestFish::query()->count())->toBe(3)
        ->and(BiggestFish::query()->where('company_name', 'Small Ltd')->value('estimated_value_gbp'))->toBe(5_000_000)
        ->and(BiggestFishScan::current()->last_order_id)->toBe(12);

    $reviews = collect(Http::recorded())
        ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://api.openai.com/v1/responses'
            && str_contains((string) ($pair[0]->data()['input'] ?? ''), 'Company name:'))
        ->count();

    expect($reviews)->toBe(3);
});

test('a numeric estimated value is shown in pounds', function () {
    $this->actingAs(User::factory()->create());

    BiggestFish::factory()->create([
        'company_name' => 'Plain Number Ltd',
        'estimated_value' => '45000000',
        'estimated_value_gbp' => 45_000_000,
    ]);
    BiggestFish::factory()->create([
        'company_name' => 'Pence Number Ltd',
        'estimated_value' => '21000000.5',
        'estimated_value_gbp' => 21_000_001,
    ]);
    BiggestFish::factory()->create([
        'company_name' => 'Written Value Ltd',
        'estimated_value' => '£40 million',
        'estimated_value_gbp' => 40_000_000,
    ]);

    $this->get(route('biggest-fish'))
        ->assertOk()
        ->assertSee('£45,000,000')
        ->assertSee('£21,000,000.50')
        ->assertSee('£40 million')
        ->assertDontSee('45000000');
});

test('the biggest fish page escapes company details', function () {
    $this->actingAs(User::factory()->create());

    BiggestFish::factory()->create([
        'company_name' => '<script>alert(1)</script>',
        'estimated_value_gbp' => 30_000_000,
    ]);

    $this->get(route('biggest-fish'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('non-admins cannot start a biggest fish check', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::biggest-fish')
        ->call('checkNow')
        ->assertForbidden();

    Http::assertNothingSent();
});

test('the scan does not run when chatgpt has no tokens', function () {
    OdooConnection::factory()->create(['url' => 'https://acme.odoo.com']);
    AiModel::factory()->create();

    $scan = new BiggestFishScan;
    $scan->last_order_id = 80;
    $scan->save();

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'error' => [
                'message' => 'You exceeded your current quota, please check your plan and billing details.',
                'type' => 'insufficient_quota',
                'code' => 'insufficient_quota',
            ],
        ], 429),
    ]);

    $this->artisan('biggest-fish:check')
        ->expectsOutputToContain('ChatGPT has no tokens left, so no customers were checked.')
        ->assertFailed();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'odoo.com'));

    expect(BiggestFish::query()->count())->toBe(0)
        ->and(BiggestFishScan::query()->value('last_order_id'))->toBe(80);
});

test('customers are checked again when chatgpt runs out of tokens mid scan', function () {
    OdooConnection::factory()->create(['url' => 'https://acme.odoo.com']);
    AiModel::factory()->create();

    $scan = new BiggestFishScan;
    $scan->last_order_id = 4;
    $scan->save();

    $researchCalls = 0;

    Http::fake(function (Request $request) use (&$researchCalls) {
        if ($request->url() === 'https://api.openai.com/v1/responses') {
            $input = (string) ($request->data()['input'] ?? '');

            if (! str_contains($input, 'Company name:')) {
                return Http::response(['output_text' => 'ok']);
            }

            $researchCalls++;

            if ($researchCalls === 2) {
                return Http::response([
                    'error' => [
                        'message' => 'You exceeded your current quota, please check your plan and billing details.',
                        'type' => 'insufficient_quota',
                        'code' => 'insufficient_quota',
                    ],
                ], 429);
            }

            $value = str_contains($input, 'Small Ltd') ? 5_000_000 : 40_000_000;

            return Http::response([
                'output_text' => json_encode([
                    'is_registered_company' => true,
                    'overview' => 'A substantial business.',
                    'company_size' => '1,000 employees',
                    'estimated_value' => $value === 5_000_000 ? '£5 million' : '£40 million',
                    'estimated_value_gbp' => $value,
                    'market_share' => 'National',
                    'logo_url' => null,
                    'website' => null,
                ]),
            ]);
        }

        return biggestFishOdooResponse($request);
    });

    $this->artisan('biggest-fish:check')
        ->expectsOutputToContain('Customers that were not reviewed will be checked again.')
        ->assertFailed();

    expect(BiggestFish::query()->where('company_name', 'Acme Ltd')->first()?->status)->toBe(CustomerReviewStatus::Complete)
        ->and(BiggestFish::query()->where('company_name', 'Small Ltd')->exists())->toBeFalse()
        ->and(BiggestFishScan::query()->value('last_order_id'))->toBe(4);

    $this->artisan('biggest-fish:check')->assertSuccessful();

    expect(BiggestFish::query()->where('company_name', 'Small Ltd')->first()?->status)->toBe(CustomerReviewStatus::Complete)
        ->and(BiggestFishScan::query()->value('last_order_id'))->toBe(11);
});

test('a customer missed when the review failed is checked again', function () {
    $this->actingAs(User::factory()->create());
    OdooConnection::factory()->create(['url' => 'https://acme.odoo.com']);
    AiModel::factory()->create();

    BiggestFish::factory()->create([
        'odoo_partner_id' => 99,
        'company_name' => 'Missed Ltd',
        'is_registered_company' => false,
        'website' => null,
        'estimated_value' => null,
        'estimated_value_gbp' => null,
        'overview' => null,
        'status' => CustomerReviewStatus::Failed,
        'failure_reason' => 'ChatGPT could not review this company.',
    ]);

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://api.openai.com/v1/responses') {
            $input = (string) ($request->data()['input'] ?? '');

            if (! str_contains($input, 'Company name:')) {
                return Http::response(['output_text' => 'ok']);
            }

            return Http::response([
                'output_text' => json_encode([
                    'is_registered_company' => true,
                    'overview' => 'A substantial business.',
                    'company_size' => '1,000 employees',
                    'estimated_value' => '£40 million',
                    'estimated_value_gbp' => 40_000_000,
                    'market_share' => 'National',
                    'logo_url' => null,
                    'website' => null,
                ]),
            ]);
        }

        return biggestFishOdooResponse($request);
    });

    $this->artisan('biggest-fish:check')->assertSuccessful();

    $this->get(route('biggest-fish'))
        ->assertOk()
        ->assertSee('Missed Ltd')
        ->assertSee('£40 million');
});

test('the check tells the user when odoo is not connected', function () {
    $this->artisan('biggest-fish:check')
        ->expectsOutputToContain('Connect Odoo before checking customers.')
        ->assertFailed();

    Http::assertNothingSent();
});

test('confirmed customers are checked every hour', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'biggest-fish:check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

/**
 * @return array<string, mixed>
 */
function biggestFishOdooResponse(Request $request): PromiseInterface
{
    $params = $request->data()['params'] ?? [];

    if (($params['method'] ?? null) === 'authenticate') {
        return Http::response(['result' => 7]);
    }

    $call = $params['args'] ?? [];
    $model = $call[3] ?? null;
    $method = $call[4] ?? null;

    if ($model === 'sale.order' && $method === 'search_read') {
        $orders = BiggestFishScan::query()->value('last_order_id') >= 11
            ? [[
                'id' => 12,
                'partner_id' => [7, 'Big Co'],
                'state' => 'sale',
            ]]
            : [
                [
                    'id' => 10,
                    'partner_id' => [9, 'Jane Smith'],
                    'state' => 'sale',
                ],
                [
                    'id' => 11,
                    'partner_id' => [8, 'Bob Jones'],
                    'state' => 'sale',
                ],
            ];

        return Http::response(['result' => $orders]);
    }

    if ($model === 'sale.order' && $method === 'read_group') {
        $companyId = collect($call[5][0] ?? [])->first(
            fn (mixed $clause): bool => is_array($clause) && ($clause[0] ?? null) === 'partner_id.commercial_partner_id',
            [],
        )[2] ?? null;

        $amount = match ($companyId) {
            5 => 4000,
            6 => 800,
            7 => 20000,
            default => 0,
        };

        return Http::response(['result' => [[
            'amount_total' => $amount,
            'currency_id' => [1, 'GBP'],
        ]]]);
    }

    $partners = [
        9 => partner(9, 'Jane Smith', 5, 'Acme Ltd'),
        8 => partner(8, 'Bob Jones', 6, 'Small Ltd'),
        7 => partner(7, 'Big Co', 7, 'Big Co', '10 Dock Road', 'Liverpool'),
        5 => partner(5, 'Acme Ltd', 5, 'Acme Ltd', '1 High Street', 'London'),
        6 => partner(6, 'Small Ltd', 6, 'Small Ltd', '2 Low Street', 'Leeds'),
    ];

    $ids = $call[5][0] ?? [];
    $rows = [];

    foreach (is_array($ids) ? $ids : [] as $id) {
        if (isset($partners[$id])) {
            $rows[] = $partners[$id];
        }
    }

    return Http::response(['result' => $rows]);
}

/**
 * @return array<string, mixed>
 */
function partner(int $id, string $name, int $companyId, string $companyName, string $street = '', string $city = ''): array
{
    return [
        'id' => $id,
        'name' => $name,
        'street' => $street !== '' ? $street : false,
        'street2' => false,
        'city' => $city !== '' ? $city : false,
        'zip' => false,
        'country_id' => false,
        'commercial_partner_id' => [$companyId, $companyName],
        'website' => false,
        'image_512' => false,
    ];
}
