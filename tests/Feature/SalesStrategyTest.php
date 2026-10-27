<?php

use App\Enums\ResearchDepth;
use App\Enums\SalesObjective;
use App\Models\AiModel;
use App\Models\BiggestFish;
use App\Models\FirstOrderDiamond;
use App\Models\OdooConnection;
use App\Models\SalesEngineSetting;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('guests cannot open a company sales strategy', function () {
    $fish = BiggestFish::factory()->create();

    $this->get(route('companies.show', $fish->odoo_partner_id))
        ->assertRedirect(route('login'));
});

test('an unknown company cannot be opened', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('companies.show', 999999))
        ->assertNotFound();
});

test('lead pages link to the company sales strategy', function () {
    $fish = BiggestFish::factory()->create();
    $diamond = FirstOrderDiamond::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('biggest-fish'))
        ->assertSee(route('companies.show', $fish->odoo_partner_id), false);

    $this->actingAs($user)
        ->get(route('first-order-diamonds'))
        ->assertSee(route('companies.show', $diamond->odoo_partner_id), false);
});

test('a sales strategy uses the odoo catalogue and can reuse the company research', function () {
    $this->actingAs(User::factory()->admin()->create());
    OdooConnection::factory()->create();
    AiModel::factory()->create();
    $fish = BiggestFish::factory()->create(['company_name' => 'Harbour Clinics']);
    $strategyCalls = 0;

    Http::fake(function (Request $request) use (&$strategyCalls) {
        if ($request->url() === 'https://api.openai.com/v1/responses') {
            if (is_array($request->data()['tools'] ?? null)) {
                return Http::response(['output_text' => json_encode(salesStrategyResearch(), JSON_THROW_ON_ERROR)]);
            }

            $strategyCalls++;

            return Http::response(['output_text' => json_encode(
                salesStrategyReport($strategyCalls === 1
                    ? 'Compare the gloves the clinics already use.'
                    : 'Review the account pricing on gloves.'),
                JSON_THROW_ON_ERROR,
            )]);
        }

        return salesStrategyOdoo($request);
    });

    Livewire::test('pages::company', ['partner' => $fish->odoo_partner_id])
        ->set('objective', SalesObjective::RepeatConsumables->value)
        ->set('researchDepth', ResearchDepth::Quick->value)
        ->set('evaluatePharmaceuticals', false)
        ->set('maxProducts', 4)
        ->call('generate')
        ->assertSee('Harbour Clinics')
        ->assertSee('86/100')
        ->assertSee('72/100')
        ->assertSee('62/100')
        ->assertSee('£1,500.00')
        ->assertSee('Already supplied')
        ->assertSee('Nitrile examination gloves')
        ->assertSee('Could also sell')
        ->assertSee('Couch roll')
        ->assertSee('COUCH-1')
        ->assertSee('£6.00')
        ->assertDontSee('GLV-100')
        ->assertSee('Compare the gloves the clinics already use.')
        ->assertSee('Scenario, not a forecast.')
        ->assertDontSee('FAKE-999')
        ->assertDontSee('Amoxicillin')
        ->call('rewrite')
        ->assertSee('Review the account pricing on gloves.');

    $settings = SalesEngineSetting::current();
    $requests = collect(Http::recorded());
    $searches = $requests->filter(fn (array $pair): bool => ($pair[0]->data()['tools'][0]['type'] ?? null) === 'web_search')->count();
    $strategyRequest = $requests->first(fn (array $pair): bool => $pair[0]->url() === 'https://api.openai.com/v1/responses' && ! is_array($pair[0]->data()['tools'] ?? null));

    expect($settings->objective)->toBe(SalesObjective::RepeatConsumables)
        ->and($settings->research_depth)->toBe(ResearchDepth::Quick)
        ->and($settings->evaluate_pharmaceuticals)->toBeFalse()
        ->and($settings->max_products)->toBe(4)
        ->and($searches)->toBe(1)
        ->and($strategyCalls)->toBe(2)
        ->and($strategyRequest[0]->data()['input'])->toContain('COUCH-1')
        ->and($strategyRequest[0]->data()['input'])->not->toContain('GLV-100')
        ->and($strategyRequest[0]->data()['input'])->not->toContain('Amoxicillin')
        ->and($strategyRequest[0]->data()['instructions'])->toContain('additional product lines');
});

test('non-admins can read a company page but cannot generate a strategy', function () {
    $fish = BiggestFish::factory()->create();
    $this->actingAs(User::factory()->create());

    $this->get(route('companies.show', $fish->odoo_partner_id))
        ->assertOk()
        ->assertSee($fish->company_name)
        ->assertSee('No sales strategy has been generated yet.')
        ->assertDontSee('Generate sales strategy');

    Livewire::test('pages::company', ['partner' => $fish->odoo_partner_id])
        ->call('generate')
        ->assertForbidden();

    Http::assertNothingSent();
});

test('generating a strategy asks for odoo when it is not connected', function () {
    $fish = BiggestFish::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::company', ['partner' => $fish->odoo_partner_id])
        ->call('generate')
        ->assertSee('Connect Odoo before building a sales strategy.');

    Http::assertNothingSent();
});

/**
 * @return array<string, mixed>
 */
function salesStrategyResearch(): array
{
    return [
        'executive_summary' => 'A private clinic group.',
        'industry' => 'Private healthcare',
        'country' => 'United Kingdom',
        'company_profile' => [
            'overview' => 'Runs outpatient clinics.',
            'activities' => 'Outpatient care',
            'sites' => '5 sites',
            'employees' => '200',
            'headquarters' => 'London',
            'ownership' => 'Private',
        ],
        'financial_research' => [
            'revenue' => 'Not publicly verified',
            'profitability' => 'Not publicly verified',
            'valuation' => 'Not publicly verified',
            'notes' => 'No filed turnover was found.',
        ],
        'buying_needs' => ['Examination gloves'],
        'decision_maker_roles' => ['Procurement Manager'],
        'sales_triggers' => ['Opened a new clinic'],
        'reason_to_contact' => 'A new clinic opening creates a supply need.',
        'catalogue_search_terms' => ['examination gloves'],
        'sources' => [
            ['title' => 'Company site', 'url' => 'https://example.com/about'],
        ],
        'information_gaps' => ['Order volumes are unknown'],
    ];
}

/**
 * @return array<string, mixed>
 */
function salesStrategyReport(string $opening): array
{
    return [
        'executive_summary' => 'Harbour Clinics can buy examination consumables regularly.',
        'strategy_summary' => 'Lead with a glove comparison across the clinics.',
        'opening_angle' => $opening,
        'industry' => 'Private healthcare',
        'country' => 'United Kingdom',
        'recommended_products' => [
            [
                'odoo_product_id' => 999,
                'sku' => 'FAKE-999',
                'use' => 'Not a real product',
                'reason' => 'Invented',
                'frequency' => 'Never',
                'repeat_order' => false,
                'confidence' => 'Low',
                'group' => 'immediate',
                'evidence' => 'None',
            ],
            [
                'odoo_product_id' => 88,
                'sku' => 'COUCH-1',
                'use' => 'Examination rooms',
                'reason' => 'Clinics use couch roll daily.',
                'frequency' => 'Monthly',
                'repeat_order' => true,
                'confidence' => 'High',
                'group' => 'recurring',
                'evidence' => 'Outpatient care',
            ],
        ],
        'opportunity_score' => [
            'total' => 86,
            'product_fit' => 90,
            'repeat_purchasing' => 80,
            'addressable_spend' => 70,
            'win_probability' => 60,
            'strategic_value' => 50,
            'research_confidence' => 'Medium',
            'priority' => 'High',
            'rationale' => 'The clinics buy consumables more often than equipment.',
            'recurring_revenue_scenario' => 'A monthly glove order is only an illustration until volumes are confirmed.',
        ],
        'sales_strategy' => [
            'recommended_tone' => 'Consultative',
            'summary' => 'Lead with a glove comparison across the clinics.',
            'buying_motivations' => ['Simpler replenishment'],
            'tactics' => [
                ['name' => 'Trial order', 'reason' => 'Make the first purchase small.'],
            ],
            'decision_makers' => ['Procurement Manager'],
            'brand_strengths' => [
                ['strength' => 'Repeat ordering', 'connection' => 'Clinics replenish gloves.'],
            ],
        ],
        'account_growth_plan' => [
            'initial_engagement' => 'Speak to procurement about current glove orders.',
            'discovery_questions' => ['Which gloves do the clinics reorder?'],
            'first_order' => 'Quote the catalogue glove.',
            'regular_purchasing' => 'Agree a replenishment list after the first order.',
            'expansion' => 'Add infection-control products once volumes are known.',
        ],
        'sales_materials' => [
            'initial_email' => [
                'subject' => 'Gloves for the clinic rooms',
                'body' => 'We can compare the examination gloves your clinics reorder.',
            ],
            'follow_up_email' => [
                'subject' => 'Following up on gloves',
                'body' => 'Happy to price the gloves you already use.',
            ],
            'telephone_script' => 'Hello, this is MidMeds. We help clinics simplify glove replenishment.',
            'linkedin_message' => '',
            'strategic_pitch' => 'MidMeds can cover the repeat clinical supplies for every site.',
            'objection_responses' => [
                ['objection' => 'We already have a supplier', 'response' => 'A comparison can sit alongside the current supplier.'],
            ],
        ],
        'next_action' => [
            'strongest_opportunity' => 'Monthly examination gloves',
            'decision_maker' => 'Procurement Manager',
            'channel' => 'Email',
            'opening_proposition' => 'Compare current glove pricing.',
            'first_products' => 'COUCH-1',
            'next_step' => 'Send a short comparison.',
            'missing_facts' => ['Monthly glove volume'],
        ],
        'sources' => [
            ['title' => 'Company site', 'url' => 'https://example.com/about'],
        ],
        'information_gaps' => ['Order volumes are unknown'],
    ];
}

function salesStrategyOdoo(Request $request): PromiseInterface
{
    $params = $request->data()['params'] ?? [];

    if (($params['method'] ?? null) === 'authenticate') {
        return Http::response(['result' => 7]);
    }

    $call = $params['args'] ?? [];
    $model = $call[3] ?? null;
    $method = $call[4] ?? null;

    if ($method === 'read_group') {
        return Http::response(['result' => [[
            'amount_total' => 1500,
            'currency_id' => [1, 'GBP'],
        ]]]);
    }

    if ($model === 'sale.order.line') {
        return Http::response(['result' => [[
            'id' => 1,
            'name' => 'Nitrile examination gloves',
            'product_id' => [55, 'Nitrile examination gloves'],
            'product_uom_qty' => 4,
            'price_subtotal' => 18,
        ]]]);
    }

    if ($model === 'product.category') {
        return Http::response(['result' => [
            ['id' => 1, 'name' => 'Consumables'],
            ['id' => 2, 'name' => 'Pharmaceuticals'],
        ]]);
    }

    if ($model === 'product.product') {
        return Http::response(['result' => [
            [
                'id' => 55,
                'name' => 'Nitrile examination gloves',
                'default_code' => 'GLV-100',
                'list_price' => 4.5,
                'categ_id' => [1, 'Consumables'],
                'qty_available' => 25,
                'description_sale' => 'Examination gloves',
                'website_url' => false,
            ],
            [
                'id' => 88,
                'name' => 'Couch roll',
                'default_code' => 'COUCH-1',
                'list_price' => 6,
                'categ_id' => [3, 'Infection control'],
                'description_sale' => 'Couch roll',
                'website_url' => false,
            ],
            [
                'id' => 77,
                'name' => 'Amoxicillin capsules',
                'default_code' => 'AMOX-10',
                'list_price' => 8,
                'categ_id' => [2, 'Pharmaceuticals'],
                'qty_available' => 10,
                'description_sale' => 'Capsules',
                'website_url' => false,
            ],
        ]]);
    }

    return Http::response(['result' => []]);
}
