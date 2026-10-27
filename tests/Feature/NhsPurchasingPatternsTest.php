<?php

use App\Enums\NhsCoverage;
use App\Models\AiModel;
use App\Models\NhsDirective;
use App\Models\NhsProductOpportunity;
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
    $this->get(route('nhs-purchasing-patterns'))
        ->assertRedirect(route('login'));
});

test('the page explains when no purchasing patterns have been found', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('nhs-purchasing-patterns'))
        ->assertOk()
        ->assertSee('No NHS purchasing patterns have been found yet.')
        ->assertDontSee('Run research');
});

test('research keeps directives and matches them to the dene catalogue', function () {
    $this->actingAs(User::factory()->admin()->create());
    AiModel::factory()->create();
    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    OdooConnection::factory()->dene()->create(['url' => 'https://dene.odoo.com']);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'midmeds.odoo.com')) {
            return Http::response(['error' => 'wrong company'], 500);
        }

        if (str_contains($request->url(), 'dene.odoo.com')) {
            return nhsPurchasingOdoo($request);
        }

        $input = $request->data()['input'] ?? '';

        if (str_contains($input, 'catalogue_products')) {
            return Http::response([
                'output_text' => json_encode([
                    'gaps' => [
                        [
                            'opportunity_name' => 'Reduced-plastic couch roll',
                            'gap_type' => 'replacement',
                            'sourcing_action' => 'bundle',
                            'gap_score' => 64,
                            'recommendation' => 'Offer the couch roll already in the catalogue and look for lower-plastic alternatives.',
                            'missing_items' => 'Lower-plastic couch roll alternatives',
                            'matched_skus' => ['FAKE-999', 'COUCH-1'],
                        ],
                        [
                            'opportunity_name' => 'Single-use plastic aprons',
                            'gap_type' => 'directive',
                            'sourcing_action' => 'monitor',
                            'gap_score' => 30,
                            'recommendation' => 'Watch for a confirmed restriction before changing the range.',
                            'missing_items' => '',
                            'matched_skus' => ['FAKE-999'],
                        ],
                    ],
                ]),
            ]);
        }

        if (str_contains($input, 'directives_to_interpret')) {
            return Http::response([
                'output_text' => json_encode([
                    'opportunities' => [
                        [
                            'directive_titles' => ['NHS carbon reporting'],
                            'name' => 'Reduced-plastic couch roll',
                            'category' => 'Consumables',
                            'demand_direction' => 'moderate_increase',
                            'timeframe' => 'medium',
                            'explanation' => 'Practices may look for lower-plastic couch roll as reporting begins.',
                            'commercial_action' => 'Compare the current couch roll with lower-plastic alternatives.',
                            'secondary_demand' => 'Waste segregation bags',
                            'sales_proposition' => 'Show practices the couch roll already supplied and ask what they want to change.',
                            'opportunity_score' => 78,
                            'confidence' => 'Medium',
                            'search_terms' => ['couch roll'],
                            'requirement_confirmed' => true,
                        ],
                        [
                            'directive_titles' => ['NHS carbon reporting'],
                            'name' => 'Single-use plastic aprons',
                            'category' => 'Consumables',
                            'demand_direction' => 'moderate_decrease',
                            'timeframe' => 'long',
                            'explanation' => 'Some disposable aprons may be bought less often if reusable alternatives are adopted.',
                            'commercial_action' => 'Monitor before removing any apron from the range.',
                            'opportunity_score' => 40,
                            'confidence' => 'Low',
                            'search_terms' => ['plastic apron'],
                            'requirement_confirmed' => true,
                        ],
                        [
                            'directive_titles' => ['Unrelated programme'],
                            'name' => 'Hospital scanner',
                            'category' => 'Equipment',
                            'demand_direction' => 'strong_increase',
                            'opportunity_score' => 90,
                            'search_terms' => ['scanner'],
                        ],
                    ],
                ]),
            ]);
        }

        return Http::response([
            'output_text' => json_encode([
                'summary' => 'One GP-relevant reporting change.',
                'changes' => 'Carbon reporting was added.',
                'limitations' => 'Not every ICB board paper could be opened.',
                'directives' => [
                    [
                        'title' => 'NHS carbon reporting',
                        'issuer' => 'NHS England',
                        'published_on' => '01/04/2026',
                        'effective_on' => '01/04/2027',
                        'obligation_type' => 'strategic',
                        'applies_to' => 'NHS trusts and some commissioned services',
                        'source_title' => 'NHS England carbon reporting',
                        'source_url' => 'https://www.england.nhs.uk/carbon-reporting',
                        'summary' => 'Organisations will report carbon, which may influence product choices later.',
                        'commercial_implications' => 'Practices may ask about lower-plastic consumables. The rule does not order them to replace stock.',
                        'confidence' => 'High',
                        'requirement_confirmed' => true,
                        'is_gp_relevant' => true,
                    ],
                    [
                        'title' => 'Trust estates standard',
                        'issuer' => 'NHS England',
                        'obligation_type' => 'binding',
                        'source_url' => 'https://www.england.nhs.uk/trust-estates',
                        'is_gp_relevant' => false,
                    ],
                    [
                        'title' => 'Missing source directive',
                        'source_url' => 'not-a-url',
                        'is_gp_relevant' => true,
                    ],
                    [
                        'title' => 'Design for Life roadmap',
                        'issuer' => 'DHSC',
                        'summary' => 'A policy direction toward reusable medical products by 2045.',
                        'source_url' => null,
                        'obligation_type' => 'strategic',
                        'relevant' => true,
                    ],
                ],
            ]),
        ]);
    });

    Livewire::test('pages::nhs-purchasing-patterns')
        ->call('run')
        ->assertSeeInOrder(['Reduced-plastic couch roll', 'Single-use plastic aprons'])
        ->assertSee('78/100')
        ->assertSee('Predicted commercial opportunity. This is not confirmation that a GP practice must buy this product.')
        ->assertSee('Partially covered')
        ->assertSee('Potential gap')
        ->assertSee('Carbon reporting was added.')
        ->assertSee('https://www.england.nhs.uk/carbon-reporting')
        ->assertSee('Design for Life roadmap')
        ->assertSee('Source link not found')
        ->assertSee('One GP-relevant reporting change.')
        ->assertDontSee('Trust estates standard')
        ->assertDontSee('Hospital scanner')
        ->assertDontSee('FAKE-999')
        ->call('run')
        ->set('view', 'growth')
        ->assertSee('Reduced-plastic couch roll')
        ->assertDontSee('Single-use plastic aprons');

    expect(NhsDirective::query()->count())->toBe(2)
        ->and(NhsProductOpportunity::query()->count())->toBe(2)
        ->and(NhsProductOpportunity::query()->where('name', 'Reduced-plastic couch roll')->first()->requirement_confirmed)->toBeFalse()
        ->and(NhsProductOpportunity::query()->where('name', 'Reduced-plastic couch roll')->first()->gap->coverage)->toBe(NhsCoverage::Partial)
        ->and(NhsProductOpportunity::query()->where('name', 'Reduced-plastic couch roll')->first()->gap->matched_products[0]['sku'])->toBe('COUCH-1');

    $recorded = collect(Http::recorded());

    expect($recorded->filter(fn (array $pair): bool => $pair[0]->url() === 'https://api.openai.com/v1/responses')->count())->toBe(6)
        ->and($recorded->contains(fn (array $pair): bool => str_contains($pair[0]->url(), 'midmeds.odoo.com')))->toBeFalse()
        ->and($recorded->contains(fn (array $pair): bool => str_contains($pair[0]->url(), 'dene.odoo.com')))->toBeTrue();

    $this->get(route('nhs-purchasing-patterns.show', NhsProductOpportunity::query()->where('name', 'Reduced-plastic couch roll')->first()))
        ->assertOk()
        ->assertSee('Couch roll')
        ->assertSee('COUCH-1')
        ->assertSee('£6.00')
        ->assertSee('Lower-plastic couch roll alternatives')
        ->assertDontSee('FAKE-999');
});

test('catalogue matching waits until the dene connection exists', function () {
    $this->actingAs(User::factory()->admin()->create());
    AiModel::factory()->create();

    Http::fake(function (Request $request) {
        $input = $request->data()['input'] ?? '';

        if (str_contains($input, 'directives_to_interpret')) {
            return Http::response([
                'output_text' => json_encode([
                    'opportunities' => [[
                        'directive_titles' => ['NHS carbon reporting'],
                        'name' => 'Reduced-plastic couch roll',
                        'category' => 'Consumables',
                        'demand_direction' => 'moderate_increase',
                        'opportunity_score' => 50,
                        'search_terms' => ['couch roll'],
                    ]],
                ]),
            ]);
        }

        return Http::response([
            'output_text' => json_encode([
                'directives' => [[
                    'title' => 'NHS carbon reporting',
                    'obligation_type' => 'strategic',
                    'source_url' => 'https://www.england.nhs.uk/carbon-reporting',
                    'is_gp_relevant' => true,
                ]],
            ]),
        ]);
    });

    Livewire::test('pages::nhs-purchasing-patterns')
        ->call('run')
        ->assertSee('Catalogue matching required.');

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->url() === 'https://api.openai.com/v1/responses')->count())->toBe(2);
});

test('a written research answer is saved as directives', function () {
    $this->actingAs(User::factory()->admin()->create());
    AiModel::factory()->create();

    Http::fake(function (Request $request) {
        $input = $request->data()['input'] ?? '';

        if (str_contains($input, 'research_notes')) {
            return Http::response([
                'output_text' => json_encode([
                    'summary' => 'Net zero supplier rules are the main change.',
                    'directives' => [[
                        'title' => 'Net Zero Supplier Roadmap',
                        'issuer' => 'NHS England',
                        'summary' => 'From April 2027 suppliers face tiered carbon reporting on new contracts.',
                        'source_url' => 'https://www.england.nhs.uk/nhs-net-zero',
                        'obligation_type' => 'procurement_policy',
                        'relevant' => true,
                    ]],
                ]),
            ]);
        }

        if (str_contains($input, 'directives_to_interpret')) {
            return Http::response([
                'output_text' => json_encode([
                    'opportunities' => [[
                        'directive_titles' => ['Net Zero Supplier Roadmap'],
                        'name' => 'Lower-carbon examination gloves',
                        'category' => 'Consumables',
                        'demand_direction' => 'moderate_increase',
                        'opportunity_score' => 70,
                        'search_terms' => ['examination gloves'],
                    ]],
                ]),
            ]);
        }

        return Http::response([
            'output_text' => 'NHS England published the Net Zero Supplier Roadmap. From 1 April 2027 carbon reporting applies to new contracts.',
        ]);
    });

    Livewire::test('pages::nhs-purchasing-patterns')
        ->call('run')
        ->assertSee('Net Zero Supplier Roadmap')
        ->assertSee('Lower-carbon examination gloves')
        ->assertSee('Net zero supplier rules are the main change.');

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->url() === 'https://api.openai.com/v1/responses')->count())->toBe(3);
});

test('the purchasing page escapes pattern names', function () {
    $this->actingAs(User::factory()->create());

    NhsProductOpportunity::query()->create([
        'fingerprint' => hash('sha256', 'xss'),
        'name' => '<script>alert(1)</script>',
        'demand_direction' => 'moderate_increase',
        'opportunity_score' => 10,
        'confidence' => 'Low',
    ]);

    $this->get(route('nhs-purchasing-patterns'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('non-admins cannot run nhs purchasing research', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::nhs-purchasing-patterns')
        ->call('run')
        ->assertForbidden();

    Http::assertNothingSent();
});

test('nhs purchasing research requires chatgpt', function () {
    $this->artisan('nhs-purchasing-patterns:check')
        ->expectsOutputToContain('Connect ChatGPT before researching NHS purchasing patterns.')
        ->assertFailed();

    Http::assertNothingSent();
});

test('nhs purchasing research runs once a week', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'nhs-purchasing-patterns:check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * 0')
        ->and($event->withoutOverlapping)->toBeTrue();
});

function nhsPurchasingOdoo(Request $request): PromiseInterface
{
    $params = $request->data()['params'] ?? [];

    if (($params['method'] ?? null) === 'authenticate') {
        return Http::response(['result' => 7]);
    }

    return Http::response([
        'result' => [[
            'id' => 88,
            'name' => 'Couch roll',
            'default_code' => 'COUCH-1',
            'list_price' => 6,
            'categ_id' => [3, 'Infection control'],
        ]],
    ]);
}
