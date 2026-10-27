<?php

use App\Enums\GpLeadStatus;
use App\Enums\GpProjectStage;
use App\Models\AiModel;
use App\Models\GpPremisesLead;
use App\Models\GpPremisesSetting;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('guests are redirected to the login page', function () {
    $this->get(route('primary-care-spaces'))
        ->assertRedirect(route('login'));

    $this->get(route('primary-care-spaces.show', 1))
        ->assertRedirect(route('login'));
});

test('the page explains when no gp premises projects have been found', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('primary-care-spaces'))
        ->assertOk()
        ->assertSee('No GP premises projects have been found yet.')
        ->assertDontSee('Run research');
});

test('research keeps one record per gp premises project', function () {
    $this->actingAs(User::factory()->admin()->create());
    AiModel::factory()->create();

    Http::fake(function (Request $request) {
        return Http::response([
            'output_text' => json_encode([
                'summary' => [
                    'geographic_coverage' => 'England',
                    'source_period' => 'Last 6 months',
                    'limitations' => 'Planning portals were not fully searched.',
                ],
                'leads' => [
                    gpPremisesResearchLead(),
                    gpPremisesResearchLead([
                        'surgery_name' => 'Harbour Practice',
                        'town' => 'Whitby',
                        'county' => 'North Yorkshire',
                        'development_type' => 'extension',
                        'development_details' => 'An extension adding two consulting rooms.',
                        'project_stage' => 'planning_submitted',
                        'source_title' => 'Whitby planning application',
                        'source_url' => 'https://planning.example/harbour',
                        'sales_priority' => 'medium',
                        'opportunity_score' => 40,
                    ]),
                    gpPremisesResearchLead([
                        'surgery_name' => 'Smile Dental',
                        'is_gp_practice' => false,
                        'source_url' => 'https://news.example/dental',
                    ]),
                    gpPremisesResearchLead([
                        'surgery_name' => 'Scotland Surgery',
                        'town' => 'Edinburgh',
                        'county' => 'Edinburgh',
                        'nation' => 'Scotland',
                        'source_url' => 'https://news.example/scotland',
                    ]),
                    gpPremisesResearchLead([
                        'surgery_name' => 'Painted Practice',
                        'development_type' => 'refurbishment',
                        'source_url' => 'https://news.example/painted',
                    ]),
                    gpPremisesResearchLead([
                        'surgery_name' => 'Riverside Rooms',
                        'town' => 'York',
                        'confirmed' => false,
                        'project_stage' => 'proposed',
                        'source_url' => 'https://news.example/riverside',
                    ]),
                    gpPremisesResearchLead([
                        'surgery_name' => 'Broken Source Practice',
                        'town' => 'Leeds',
                        'source_url' => 'not-a-url',
                    ]),
                    gpPremisesResearchLead([
                        'surgery_name' => 'Old Surgery',
                        'town' => 'Bath',
                        'project_stage' => 'completed',
                        'sales_priority' => 'low',
                        'opportunity_score' => 10,
                        'source_url' => 'https://news.example/old',
                    ]),
                ],
            ]),
        ]);
    });

    Livewire::test('pages::primary-care-spaces')
        ->set('geography', 'england')
        ->set('sourceWindowMonths', 6)
        ->set('futureHorizonMonths', 36)
        ->set('includeRefurbishments', false)
        ->call('run')
        ->assertSeeInOrder(['Phoenix Health Group', 'Harbour Practice'])
        ->assertSee('90/100')
        ->assertSee('Consultation room furniture and waiting room seating.')
        ->assertSee('https://nhsglos.example/phoenix')
        ->assertSee('https://phoenix.example')
        ->assertSee('Research watchlist')
        ->assertSee('Riverside Rooms')
        ->assertSee('Broken Source Practice')
        ->assertSee('Completed and cancelled')
        ->assertSee('Old Surgery')
        ->assertSee('Planning portals were not fully searched.')
        ->assertDontSee('Smile Dental')
        ->assertDontSee('Scotland Surgery')
        ->assertDontSee('Painted Practice')
        ->call('run');

    expect(GpPremisesLead::query()->count())->toBe(5)
        ->and(GpPremisesLead::query()->where('surgery_name', 'Phoenix Health Group')->count())->toBe(1)
        ->and(GpPremisesLead::query()->where('surgery_name', 'Riverside Rooms')->first()->status)->toBe(GpLeadStatus::Watchlist)
        ->and(GpPremisesLead::query()->where('surgery_name', 'Old Surgery')->first()->status)->toBe(GpLeadStatus::Inactive)
        ->and(GpPremisesSetting::current()->geography->value)->toBe('england')
        ->and(GpPremisesSetting::current()->source_window_months)->toBe(6)
        ->and(GpPremisesSetting::current()->future_horizon_months)->toBe(36)
        ->and(GpPremisesSetting::current()->development_types)->not->toContain('refurbishment');

    $searches = collect(Http::recorded())
        ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://api.openai.com/v1/responses')
        ->count();

    expect($searches)->toBe(2);
});

test('high priority search settings hide lower priority projects', function () {
    $this->actingAs(User::factory()->admin()->create());
    AiModel::factory()->create();

    Http::fake(fn () => Http::response([
        'output_text' => json_encode([
            'summary' => ['limitations' => ''],
            'leads' => [
                gpPremisesResearchLead(),
                gpPremisesResearchLead([
                    'surgery_name' => 'Harbour Practice',
                    'town' => 'Whitby',
                    'development_type' => 'extension',
                    'source_url' => 'https://planning.example/harbour',
                    'sales_priority' => 'medium',
                    'opportunity_score' => 40,
                ]),
            ],
        ]),
    ]));

    Livewire::test('pages::primary-care-spaces')
        ->set('highPriorityOnly', '1')
        ->call('run')
        ->assertSee('Phoenix Health Group')
        ->assertDontSee('Harbour Practice');

    expect(GpPremisesLead::query()->where('surgery_name', 'Harbour Practice')->exists())->toBeTrue();
});

test('the premises page escapes surgery names', function () {
    $this->actingAs(User::factory()->create());

    GpPremisesLead::query()->create([
        'fingerprint' => hash('sha256', 'xss'),
        'surgery_name' => '<script>alert(1)</script>',
        'nation' => 'england',
        'development_type' => 'new_building',
        'project_stage' => 'proposed',
        'stage_rank' => 3,
        'status' => 'active',
        'sales_priority' => 'high',
        'opportunity_score' => 10,
        'source_confidence' => 'Low',
    ]);

    $this->get(route('primary-care-spaces'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('non-admins cannot run gp premises research', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::primary-care-spaces')
        ->call('run')
        ->assertForbidden();

    Http::assertNothingSent();
});

test('a project page shows the fit-out record and can be checked again', function () {
    $this->actingAs(User::factory()->admin()->create());
    AiModel::factory()->create();

    $lead = GpPremisesLead::query()->create([
        'fingerprint' => hash('sha256', 'phoenix'),
        'surgery_name' => 'Phoenix Health Group',
        'website' => 'https://phoenix.example',
        'town' => 'Tetbury',
        'county' => 'Gloucestershire',
        'nation' => 'england',
        'development_type' => 'new_building',
        'development_details' => 'A new surgery building with 16 consulting rooms.',
        'project_stage' => 'under_construction',
        'stage_rank' => 4,
        'status' => 'active',
        'source_title' => 'NHS Gloucestershire announcement',
        'source_published_on' => '16/12/2025',
        'source_url' => 'https://nhsglos.example/phoenix',
        'sources' => [[
            'title' => 'NHS Gloucestershire announcement',
            'url' => 'https://nhsglos.example/phoenix',
            'published_on' => '16/12/2025',
        ]],
        'fit_out_opportunity' => 'Consultation room furniture and waiting room seating.',
        'next_action' => 'Speak to the practice about consultation room furniture.',
        'sales_priority' => 'high',
        'opportunity_score' => 90,
        'source_confidence' => 'High',
    ]);

    Http::fake(fn () => Http::response([
        'output_text' => json_encode(gpPremisesResearchLead([
            'project_stage' => 'fit_out',
            'opportunity_score' => 95,
            'source_title' => 'Opening update',
            'source_url' => 'https://nhsglos.example/phoenix-update',
            'source_published_on' => '01/03/2026',
        ])),
    ]));

    $this->get(route('primary-care-spaces.show', $lead))
        ->assertOk()
        ->assertSee('Consultation room furniture and waiting room seating.')
        ->assertSee('Speak to the practice about consultation room furniture.')
        ->assertSee('Check this project');

    Livewire::test('pages::gp-premises-project', ['lead' => $lead])
        ->call('check')
        ->assertSee('Fit-out')
        ->assertSee('95/100')
        ->assertSee('https://nhsglos.example/phoenix-update');

    expect($lead->refresh()->project_stage)->toBe(GpProjectStage::FitOut)
        ->and($lead->sources)->toHaveCount(2);
});

test('gp premises research requires chatgpt', function () {
    $this->artisan('primary-care-spaces:check')
        ->expectsOutputToContain('Connect ChatGPT before researching GP premises.')
        ->assertFailed();

    Http::assertNothingSent();
});

test('the daily check refreshes saved projects before searching again', function () {
    AiModel::factory()->create();

    GpPremisesLead::query()->create([
        'fingerprint' => hash('sha256', 'harbour'),
        'surgery_name' => 'Harbour Practice',
        'town' => 'Whitby',
        'nation' => 'england',
        'development_type' => 'extension',
        'project_stage' => 'planning_submitted',
        'stage_rank' => 3,
        'status' => 'active',
        'source_url' => 'https://planning.example/harbour',
        'sources' => [[
            'title' => 'Whitby planning application',
            'url' => 'https://planning.example/harbour',
            'published_on' => '01/02/2026',
        ]],
        'sales_priority' => 'medium',
        'opportunity_score' => 40,
        'source_confidence' => 'Medium',
    ]);

    Http::fake(function (Request $request) {
        $input = $request->data()['input'] ?? '';

        if (str_contains($input, 'project_to_check')) {
            return Http::response([
                'output_text' => json_encode(gpPremisesResearchLead([
                    'surgery_name' => 'Harbour Practice',
                    'town' => 'Whitby',
                    'development_type' => 'extension',
                    'project_stage' => 'planning_approved',
                    'source_url' => 'https://planning.example/harbour-approved',
                    'sales_priority' => 'high',
                    'opportunity_score' => 80,
                ])),
            ]);
        }

        return Http::response([
            'output_text' => json_encode([
                'summary' => ['limitations' => 'No further projects found.'],
                'leads' => [],
            ]),
        ]);
    });

    $this->artisan('primary-care-spaces:check')
        ->expectsOutputToContain('Checked 1 projects. Added 0. Updated 1.')
        ->assertSuccessful();

    expect(GpPremisesLead::query()->count())->toBe(1)
        ->and(GpPremisesLead::query()->first()->project_stage)->toBe(GpProjectStage::PlanningApproved)
        ->and(GpPremisesLead::query()->first()->sources)->toHaveCount(2);
});

test('gp premises research runs once a day', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'primary-care-spaces:check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gpPremisesResearchLead(array $overrides = []): array
{
    return array_replace([
        'surgery_name' => 'Phoenix Health Group',
        'website' => 'https://phoenix.example',
        'town' => 'Tetbury',
        'county' => 'Gloucestershire',
        'nation' => 'England',
        'is_gp_practice' => true,
        'confirmed' => true,
        'development_type' => 'new_building',
        'development_details' => 'A new surgery building with 16 consulting rooms.',
        'project_stage' => 'under_construction',
        'source_title' => 'NHS Gloucestershire announcement',
        'source_published_on' => '16/12/2025',
        'source_url' => 'https://nhsglos.example/phoenix',
        'project_dates' => 'Opening winter 2026/27',
        'funding' => '£5 million development',
        'planning_reference' => null,
        'planning_authority' => null,
        'source_confidence' => 'High',
        'fit_out_opportunity' => 'Consultation room furniture and waiting room seating. These are possible requirements, not a confirmed tender.',
        'sales_timing' => 'Contact while construction is underway.',
        'sales_priority' => 'high',
        'opportunity_score' => 90,
        'next_action' => 'Speak to the practice about consultation room furniture.',
        'summary' => 'Construction is underway.',
    ], $overrides);
}
