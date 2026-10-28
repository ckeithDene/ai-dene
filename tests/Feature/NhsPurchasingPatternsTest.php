<?php

use App\Actions\ResearchNhsPurchasing;
use App\Enums\NhsCoverage;
use App\Enums\NhsDemandDirection;
use App\Enums\NhsGapType;
use App\Enums\NhsObligation;
use App\Enums\NhsSourcingAction;
use App\Models\AiModel;
use App\Models\NhsCatalogueGap;
use App\Models\NhsDirective;
use App\Models\NhsProductOpportunity;
use App\Models\NhsPurchasingSetting;
use App\Models\OdooConnection;
use App\Models\User;
use App\Services\NhsPurchasingResearcher;
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

    $this->get(route('nhs-purchasing-patterns.directives.show', 1))
        ->assertRedirect(route('login'));
});

test('the page explains when no purchasing patterns have been found', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('nhs-purchasing-patterns'))
        ->assertOk()
        ->assertSee('No NHS purchasing patterns have been found yet.')
        ->assertDontSee('Run research');
});

test('nhs purchasing research uses the original brief one stage at a time', function () {
    $aiModel = AiModel::factory()->create();

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'output_text' => json_encode([
                'summary' => 'Directives first.',
                'directives' => [[
                    'title' => 'Community diagnostic centres',
                    'summary' => 'More tests are moving out of hospital.',
                    'source_url' => 'https://www.england.nhs.uk/diagnostics',
                    'obligation_type' => 'strategic',
                ]],
                'opportunities' => [],
            ]),
        ]),
    ]);

    app(NhsPurchasingResearcher::class)->searchStage($aiModel, 'clinical', []);

    Http::assertSent(function (Request $request): bool {
        $instructions = $request->data()['instructions'] ?? '';
        $input = json_decode((string) ($request->data()['input'] ?? ''), true);

        return $request->url() === 'https://api.openai.com/v1/responses'
            && str_contains($instructions, 'Sustainability is only one possible theme')
            && str_contains($instructions, 'clinical, community, diagnostics, digital, workforce')
            && str_contains($instructions, 'Use one web search for the stage named in the user message')
            && ($request->data()['tools'][0]['search_context_size'] ?? null) === 'low'
            && is_array($input)
            && ($input['search_target'] ?? null) === 'clinical'
            && str_contains((string) ($input['task'] ?? ''), 'clinical guidance, community care and diagnostics');
    });
});

test('nhs purchasing research uses a separate chatgpt call for each source', function () {
    AiModel::factory()->create();

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'output_text' => json_encode([
                'summary' => 'Community diagnostics are the main change.',
                'directives' => [[
                    'title' => 'Community diagnostic centres',
                    'summary' => 'More tests are moving out of hospital.',
                    'source_url' => 'https://www.england.nhs.uk/diagnostics',
                    'obligation_type' => 'strategic',
                ]],
                'opportunities' => [[
                    'directive_titles' => ['Community diagnostic centres'],
                    'name' => 'Diagnostic couch',
                    'demand_direction' => 'moderate_increase',
                    'opportunity_score' => 60,
                    'confidence' => 'Medium',
                ]],
            ]),
        ]),
    ]);

    app(ResearchNhsPurchasing::class)->handle();

    $targets = collect(Http::recorded())
        ->map(function (array $pair): ?string {
            $input = json_decode((string) ($pair[0]->data()['input'] ?? ''), true);

            return is_array($input) ? ($input['search_target'] ?? null) : null;
        })
        ->filter()
        ->values()
        ->all();

    expect(NhsDirective::query()->where('title', 'Community diagnostic centres')->exists())->toBeTrue()
        ->and(NhsProductOpportunity::query()->where('name', 'Diagnostic couch')->exists())->toBeTrue()
        ->and($targets)->toBe(['clinical', 'procurement', 'operations', 'opportunities']);
});

test('nhs purchasing research keeps every initiative the staged search returns', function () {
    $this->actingAs(User::factory()->create());
    AiModel::factory()->create();

    $directives = [
        ['title' => 'Community diagnostic centres', 'source_url' => 'https://www.england.nhs.uk/diagnostics', 'summary' => 'More tests in the community.', 'obligation_type' => 'strategic'],
        ['title' => 'GP premises improvement', 'source_url' => 'https://www.england.nhs.uk/premises', 'summary' => 'Funding for practice buildings.', 'obligation_type' => 'funded'],
        ['title' => 'Digital primary care tools', 'source_url' => 'https://www.england.nhs.uk/digital', 'summary' => 'Practices may buy more digital tools.', 'obligation_type' => 'strategic'],
        ['title' => 'Wound care formulary', 'source_url' => 'https://www.england.nhs.uk/wound-care', 'summary' => 'A shared formulary for dressings.', 'obligation_type' => 'guideline'],
    ];

    for ($number = 1; $number <= 10; $number++) {
        $directives[] = [
            'title' => 'Practice staffing news '.$number,
            'source_url' => 'https://www.england.nhs.uk/news/staffing-'.$number,
            'summary' => 'A news story about practice staffing.',
            'obligation_type' => 'strategic',
        ];
    }

    $directives[] = [
        'title' => 'Greener NHS couch roll',
        'source_url' => 'https://www.england.nhs.uk/greenernhs/couch-roll',
        'summary' => 'Net zero purchasing for couch roll.',
        'obligation_type' => 'strategic',
    ];

    for ($number = 2; $number <= 12; $number++) {
        $directives[] = [
            'title' => 'Greener NHS item '.$number,
            'source_url' => 'https://www.england.nhs.uk/greenernhs/item-'.$number,
            'summary' => 'A sustainability update.',
            'obligation_type' => 'strategic',
        ];
    }

    $opportunities = [];

    for ($number = 1; $number <= 14; $number++) {
        $opportunities[] = [
            'directive_titles' => ['Community diagnostic centres'],
            'name' => 'Diagnostic product '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'demand_direction' => 'moderate_increase',
            'opportunity_score' => 101 - $number,
            'confidence' => 'Medium',
        ];
    }

    for ($number = 1; $number <= 9; $number++) {
        $opportunities[] = [
            'directive_titles' => ['Practice staffing news '.$number],
            'name' => 'Staffing product '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'demand_direction' => 'moderate_increase',
            'opportunity_score' => 40,
            'confidence' => 'Low',
        ];
    }

    foreach (['Couch roll', 'Reduced-plastic couch roll', 'Lower-carbon couch roll', 'Sustainable couch roll', 'Reusable couch roll'] as $name) {
        $opportunities[] = [
            'directive_titles' => ['Greener NHS couch roll'],
            'name' => $name,
            'demand_direction' => 'moderate_increase',
            'opportunity_score' => $name === 'Couch roll' ? 90 : 10,
            'confidence' => 'Low',
        ];
    }

    for ($number = 1; $number <= 3; $number++) {
        $opportunities[] = [
            'directive_titles' => ['Greener NHS item 2'],
            'name' => 'Green gadget '.$number,
            'demand_direction' => 'moderate_increase',
            'opportunity_score' => 20,
            'confidence' => 'Low',
        ];
    }

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'output_text' => json_encode([
                'summary' => 'Directives lead the forecast.',
                'directives' => $directives,
                'opportunities' => $opportunities,
            ]),
        ]),
    ]);

    app(ResearchNhsPurchasing::class)->handle();

    $groups = NhsDirective::query()->get()->countBy(fn (NhsDirective $directive): string => $directive->researchGroup());

    expect($groups->get('directive'))->toBe(4)
        ->and($groups->get('news'))->toBe(10)
        ->and($groups->get('greener'))->toBe(12)
        ->and(NhsProductOpportunity::query()->where('name', 'like', 'Diagnostic product%')->count())->toBe(14)
        ->and(NhsProductOpportunity::query()->where('name', 'Diagnostic product 09')->exists())->toBeTrue()
        ->and(NhsProductOpportunity::query()->where('name', 'like', 'Staffing product%')->count())->toBe(9)
        ->and(NhsProductOpportunity::query()->where('name', 'Staffing product 09')->exists())->toBeTrue()
        ->and(NhsProductOpportunity::query()->where('name', 'Couch roll')->exists())->toBeTrue()
        ->and(NhsProductOpportunity::query()->where('name', 'like', '%couch%')->count())->toBe(1)
        ->and(NhsProductOpportunity::query()->where('name', 'Green gadget 1')->exists())->toBeTrue()
        ->and(NhsProductOpportunity::query()->where('name', 'like', 'Green gadget%')->count())->toBe(3);

    $this->get(route('nhs-purchasing-patterns'))
        ->assertOk()
        ->assertSeeInOrder(['Directives', 'News and blogs', 'Greener NHS']);
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
                            'matched_skus' => ['FAKE-999', 'COUCH-1', 'FIT-1'],
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
                            'rationale' => 'Net zero and product footprinting',
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
                            'rationale' => 'Potential replacement where clinically appropriate',
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
                'opportunities' => [
                    [
                        'directive_titles' => ['NHS carbon reporting'],
                        'name' => 'Reduced-plastic couch roll',
                        'category' => 'Consumables',
                        'demand_direction' => 'moderate_increase',
                        'explanation' => 'Practices may look for lower-plastic couch roll as reporting begins.',
                        'rationale' => 'Net zero and product footprinting',
                        'sales_proposition' => 'Show practices the couch roll already supplied and ask what they want to change.',
                        'opportunity_score' => 78,
                        'confidence' => 'Medium',
                        'search_terms' => ['couch roll'],
                    ],
                    [
                        'directive_titles' => ['NHS carbon reporting'],
                        'name' => 'Single-use plastic aprons',
                        'category' => 'Consumables',
                        'demand_direction' => 'moderate_decrease',
                        'explanation' => 'Some disposable aprons may be bought less often if reusable alternatives are adopted.',
                        'rationale' => 'Potential replacement where clinically appropriate',
                        'opportunity_score' => 40,
                        'confidence' => 'Low',
                        'search_terms' => ['plastic apron'],
                    ],
                ],
            ]),
        ]);
    });

    $component = finishNhsResearch(Livewire::test('pages::nhs-purchasing-patterns')->call('run'));
    $component
        ->assertSeeInOrder(['Product category', 'Expected direction', 'Rationale', 'Reduced-plastic couch roll', 'Opportunity', 'Net zero and product footprinting', 'Single-use plastic aprons', 'Pressure', 'Potential replacement where clinically appropriate'])
        ->assertSee('78/100')
        ->assertSee('Predicted commercial opportunity. This is not confirmation that a GP practice must buy this product.')
        ->assertSee('Partially covered')
        ->assertSee('Potential gap')
        ->assertSee('Carbon reporting was added.')
        ->assertSee('https://www.england.nhs.uk/carbon-reporting')
        ->assertSee('The rule does not order them to replace stock.')
        ->assertSee('Design for Life roadmap')
        ->assertSee('View purchasing impact')
        ->assertSee(route('nhs-purchasing-patterns.directives.show', NhsDirective::query()->where('title', 'NHS carbon reporting')->first()), false)
        ->assertSee('Source link not found')
        ->assertSee('One GP-relevant reporting change.')
        ->assertDontSee('Trust estates standard')
        ->assertDontSee('Hospital scanner')
        ->assertDontSee('FAKE-999');

    finishNhsResearch($component->call('run'))
        ->set('view', 'growth')
        ->assertSee('Reduced-plastic couch roll')
        ->assertDontSee('Single-use plastic aprons');

    expect(NhsDirective::query()->count())->toBe(2)
        ->and(NhsProductOpportunity::query()->count())->toBe(2)
        ->and(NhsProductOpportunity::query()->where('name', 'Reduced-plastic couch roll')->first()->requirement_confirmed)->toBeFalse()
        ->and(NhsProductOpportunity::query()->where('name', 'Reduced-plastic couch roll')->first()->gap->coverage)->toBe(NhsCoverage::Partial)
        ->and(NhsProductOpportunity::query()->where('name', 'Reduced-plastic couch roll')->first()->gap->matched_products[0]['sku'])->toBe('COUCH-1');

    $recorded = collect(Http::recorded());

    expect($recorded->filter(fn (array $pair): bool => $pair[0]->url() === 'https://api.openai.com/v1/responses')->count())->toBe(10)
        ->and($recorded->contains(fn (array $pair): bool => str_contains($pair[0]->url(), 'midmeds.odoo.com')))->toBeFalse()
        ->and($recorded->contains(fn (array $pair): bool => str_contains($pair[0]->url(), 'dene.odoo.com')))->toBeTrue();

    $pattern = NhsProductOpportunity::query()->where('name', 'Reduced-plastic couch roll')->first();

    $this->get(route('nhs-purchasing-patterns.show', $pattern))
        ->assertOk()
        ->assertSee('What to push')
        ->assertSee('Show practices the couch roll already supplied and ask what they want to change.')
        ->assertSee('Products that could benefit')
        ->assertSee('Couch roll')
        ->assertSee('COUCH-1')
        ->assertSee('£6.00')
        ->assertSee('Services that could help')
        ->assertSee('Consultation room fit-out')
        ->assertSee('FIT-1')
        ->assertSee('Lower-plastic couch roll alternatives')
        ->assertDontSee('FAKE-999');

    $this->get(route('nhs-purchasing-patterns.directives.show', NhsDirective::query()->where('title', 'NHS carbon reporting')->first()))
        ->assertOk()
        ->assertSee('Potential impact on GP purchasing')
        ->assertSee('The rule does not order them to replace stock.')
        ->assertSee('Reduced-plastic couch roll')
        ->assertSee('Couch roll')
        ->assertSee('Consultation room fit-out');
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
                    'summary' => 'Organisations will report carbon.',
                    'is_gp_relevant' => true,
                ]],
                'opportunities' => [[
                    'directive_titles' => ['NHS carbon reporting'],
                    'name' => 'Reduced-plastic couch roll',
                    'demand_direction' => 'moderate_increase',
                    'opportunity_score' => 50,
                    'search_terms' => ['couch roll'],
                ]],
            ]),
        ]);
    });

    finishNhsResearch(Livewire::test('pages::nhs-purchasing-patterns')->call('run'))
        ->assertSee('Catalogue matching required.');

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->url() === 'https://api.openai.com/v1/responses')->count())->toBe(4);
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

    finishNhsResearch(Livewire::test('pages::nhs-purchasing-patterns')->call('run'))
        ->assertSee('Net Zero Supplier Roadmap')
        ->assertSee('Lower-carbon examination gloves')
        ->assertSee('Net zero supplier rules are the main change.');

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->url() === 'https://api.openai.com/v1/responses')->count())->toBe(7);
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

test('finding dene products uses the dene connection and ignores midmeds', function () {
    $this->actingAs(User::factory()->admin()->create());
    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    OdooConnection::factory()->dene()->create(['url' => 'https://dene.odoo.com']);
    [$directive, $opportunity] = deneCataloguePattern();

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'midmeds.odoo.com')) {
            return Http::response(['error' => 'wrong company'], 500);
        }

        $params = $request->data()['params'] ?? [];

        if (($params['method'] ?? null) === 'authenticate') {
            return Http::response(['result' => 7]);
        }

        if (($params['args'][3] ?? null) === 'product.category') {
            return Http::response([
                'result' => [[
                    'id' => 3,
                    'name' => 'Furniture',
                ]],
            ]);
        }

        $domain = $params['args'][5][0] ?? [];
        $serviceOnly = false;

        if (is_array($domain)) {
            foreach ($domain as $clause) {
                if (is_array($clause) && ($clause[0] ?? null) === 'type' && ($clause[1] ?? null) === '=' && ($clause[2] ?? null) === 'service') {
                    $serviceOnly = true;
                }
            }
        }

        if ($serviceOnly) {
            return Http::response([
                'result' => [[
                    'id' => 91,
                    'name' => 'Consultation room fit-out',
                    'default_code' => 'FIT-1',
                    'list_price' => 1200,
                    'categ_id' => [4, 'Services'],
                    'type' => 'service',
                ]],
            ]);
        }

        return Http::response([
            'result' => [[
                'id' => 88,
                'name' => 'Couch roll',
                'default_code' => 'COUCH-1',
                'list_price' => 6,
                'categ_id' => [3, 'Furniture'],
                'type' => 'consu',
            ]],
        ]);
    });

    Livewire::test('pages::nhs-purchasing-pattern', ['opportunity' => $opportunity])
        ->call('findProducts')
        ->assertSee('Couch roll')
        ->assertSee('COUCH-1')
        ->assertSee('Consultation room fit-out')
        ->assertSee('Furniture')
        ->assertSee('dene.odoo.com');

    Livewire::test('pages::nhs-purchasing-directive', ['directive' => $directive])
        ->call('findProducts')
        ->assertSee('Couch roll')
        ->assertSee('Consultation room fit-out')
        ->assertSee('Product categories');

    expect(collect(Http::recorded())->contains(fn (array $pair): bool => str_contains($pair[0]->url(), 'midmeds.odoo.com')))->toBeFalse()
        ->and(collect(Http::recorded())->contains(fn (array $pair): bool => str_contains($pair[0]->url(), 'dene.odoo.com')))->toBeTrue();
});

test('a catalogue search does not use the midmeds connection', function () {
    $this->actingAs(User::factory()->admin()->create());
    OdooConnection::factory()->create(['url' => 'https://midmeds.odoo.com']);
    [, $opportunity] = deneCataloguePattern();

    Livewire::test('pages::nhs-purchasing-pattern', ['opportunity' => $opportunity])
        ->call('findProducts')
        ->assertSee('The saved MidMeds connection is not used here.');

    Http::assertNothingSent();
});

test('an admin can remove all nhs research', function () {
    $this->actingAs(User::factory()->admin()->create());
    [$kept] = savedNhsResearch();

    $this->get(route('nhs-purchasing-patterns'))
        ->assertOk()
        ->assertSee('data-test="clear-nhs-research"', false)
        ->assertSee('data-test="refresh-nhs-research"', false)
        ->assertSee('data-test="remove-nhs-opportunity-'.$kept->id.'"', false);

    Livewire::test('pages::nhs-purchasing-patterns')
        ->call('clearResearch')
        ->assertOk();

    expect(NhsDirective::query()->count())->toBe(0)
        ->and(NhsProductOpportunity::query()->count())->toBe(0)
        ->and(NhsCatalogueGap::query()->count())->toBe(0)
        ->and(NhsPurchasingSetting::current()->last_summary)->toBeNull()
        ->and(NhsPurchasingSetting::current()->last_changes)->toBeNull()
        ->and(NhsPurchasingSetting::current()->last_limitations)->toBeNull();

    $this->get(route('nhs-purchasing-patterns'))
        ->assertOk()
        ->assertSee('No NHS purchasing patterns have been found yet.')
        ->assertDontSee('Couch roll demand')
        ->assertDontSee('Old NHS summary');
});

test('an admin can remove one purchasing pattern or directive', function () {
    $this->actingAs(User::factory()->admin()->create());
    [$kept, $removed, $directive] = savedNhsResearch();

    Livewire::test('pages::nhs-purchasing-patterns')
        ->call('removeOpportunity', $removed->id)
        ->assertOk();

    expect(NhsProductOpportunity::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(NhsProductOpportunity::query()->whereKey($removed->id)->exists())->toBeFalse()
        ->and(NhsCatalogueGap::query()->where('nhs_product_opportunity_id', $removed->id)->exists())->toBeFalse()
        ->and(NhsDirective::query()->whereKey($directive->id)->exists())->toBeTrue();

    Livewire::test('pages::nhs-purchasing-patterns')
        ->call('removeDirective', $directive->id)
        ->assertOk();

    expect(NhsDirective::query()->count())->toBe(0)
        ->and(NhsProductOpportunity::query()->whereKey($kept->id)->exists())->toBeTrue();
});

test('refreshing nhs research clears the saved record before checking again', function () {
    $this->actingAs(User::factory()->admin()->create());
    savedNhsResearch();

    Livewire::test('pages::nhs-purchasing-patterns')
        ->call('refreshResearch')
        ->assertOk();

    expect(NhsDirective::query()->count())->toBe(0)
        ->and(NhsProductOpportunity::query()->count())->toBe(0)
        ->and(NhsCatalogueGap::query()->count())->toBe(0);

    Http::assertNothingSent();
});

test('non-admins cannot remove nhs research', function () {
    $this->actingAs(User::factory()->create());
    [, $opportunity, $directive] = savedNhsResearch();

    $this->get(route('nhs-purchasing-patterns'))
        ->assertOk()
        ->assertDontSee('data-test="clear-nhs-research"', false)
        ->assertDontSee('data-test="remove-nhs-opportunity-'.$opportunity->id.'"', false);

    Livewire::test('pages::nhs-purchasing-patterns')->call('clearResearch')->assertForbidden();
    Livewire::test('pages::nhs-purchasing-patterns')->call('refreshResearch')->assertForbidden();
    Livewire::test('pages::nhs-purchasing-patterns')->call('removeOpportunity', $opportunity->id)->assertForbidden();
    Livewire::test('pages::nhs-purchasing-patterns')->call('removeDirective', $directive->id)->assertForbidden();

    expect(NhsDirective::query()->count())->toBe(1)
        ->and(NhsProductOpportunity::query()->count())->toBe(2);
});

test('non-admins cannot search the dene catalogue', function () {
    $this->actingAs(User::factory()->create());
    [, $opportunity] = deneCataloguePattern();

    Livewire::test('pages::nhs-purchasing-pattern', ['opportunity' => $opportunity])
        ->call('findProducts')
        ->assertForbidden();

    Http::assertNothingSent();
});

/**
 * @return array{0: NhsProductOpportunity, 1: NhsProductOpportunity, 2: NhsDirective}
 */
function savedNhsResearch(): array
{
    $directive = NhsDirective::query()->create([
        'fingerprint' => hash('sha256', 'saved-directive'),
        'title' => 'NHS carbon reporting',
        'obligation' => NhsObligation::Strategic,
        'confidence' => 'Medium',
    ]);
    $kept = NhsProductOpportunity::query()->create([
        'fingerprint' => hash('sha256', 'kept-pattern'),
        'name' => 'Examination gloves',
        'demand_direction' => NhsDemandDirection::ModerateIncrease,
        'opportunity_score' => 40,
        'confidence' => 'Medium',
    ]);
    $removed = NhsProductOpportunity::query()->create([
        'fingerprint' => hash('sha256', 'removed-pattern'),
        'name' => 'Couch roll demand',
        'demand_direction' => NhsDemandDirection::ModerateDecrease,
        'opportunity_score' => 20,
        'confidence' => 'Low',
    ]);
    $directive->opportunities()->attach([$kept->id, $removed->id]);
    NhsCatalogueGap::query()->create([
        'nhs_product_opportunity_id' => $removed->id,
        'gap_type' => NhsGapType::Directive,
        'coverage' => NhsCoverage::PotentialGap,
        'sourcing_action' => NhsSourcingAction::Monitor,
    ]);
    $settings = NhsPurchasingSetting::current();
    $settings->last_summary = 'Old NHS summary';
    $settings->last_changes = 'Old change note';
    $settings->last_limitations = 'Old limitation';
    $settings->last_ran_at = now();
    $settings->save();

    return [$kept, $removed, $directive];
}

function finishNhsResearch(object $component): object
{
    $guard = 0;

    while ($component->get('researchStep') !== '' && $guard < 8) {
        $component->call('advance');
        $guard++;
    }

    return $component;
}

function deneCataloguePattern(): array
{
    $directive = NhsDirective::query()->create([
        'fingerprint' => hash('sha256', 'dene-catalogue-directive'),
        'title' => 'NHS carbon reporting',
        'obligation' => NhsObligation::Strategic,
        'commercial_implications' => 'Practices may ask about lower-plastic consumables.',
        'confidence' => 'Medium',
    ]);
    $opportunity = NhsProductOpportunity::query()->create([
        'fingerprint' => hash('sha256', 'dene-catalogue-couch'),
        'name' => 'Reduced-plastic couch roll',
        'category' => 'Consumables',
        'demand_direction' => NhsDemandDirection::ModerateIncrease,
        'search_terms' => ['couch roll'],
        'opportunity_score' => 70,
        'confidence' => 'Medium',
    ]);
    $directive->opportunities()->attach($opportunity);

    return [$directive, $opportunity];
}

function nhsPurchasingOdoo(Request $request): PromiseInterface
{
    $params = $request->data()['params'] ?? [];

    if (($params['method'] ?? null) === 'authenticate') {
        return Http::response(['result' => 7]);
    }

    $domain = $params['args'][5][0] ?? [];
    $serviceOnly = false;

    if (is_array($domain)) {
        foreach ($domain as $clause) {
            if (is_array($clause) && ($clause[0] ?? null) === 'type' && ($clause[1] ?? null) === '=' && ($clause[2] ?? null) === 'service') {
                $serviceOnly = true;
            }
        }
    }

    if ($serviceOnly) {
        return Http::response([
            'result' => [[
                'id' => 91,
                'name' => 'Consultation room fit-out',
                'default_code' => 'FIT-1',
                'list_price' => 1200,
                'categ_id' => [4, 'Services'],
                'type' => 'service',
            ]],
        ]);
    }

    return Http::response([
        'result' => [[
            'id' => 88,
            'name' => 'Couch roll',
            'default_code' => 'COUCH-1',
            'list_price' => 6,
            'categ_id' => [3, 'Infection control'],
            'type' => 'consu',
        ]],
    ]);
}
