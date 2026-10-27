<?php

use App\Actions\GenerateSalesStrategy;
use App\Data\SalesProspect;
use App\Enums\OdooCompany;
use App\Enums\ResearchDepth;
use App\Enums\SalesObjective;
use App\Enums\SalesStrategyStatus;
use App\Enums\SalesTone;
use App\Models\OdooConnection;
use App\Models\SalesEngineSetting;
use App\Models\SalesStrategy;
use App\Services\MidMedsCatalogue;
use App\Services\OdooClient;
use App\Services\SalesProspectFinder;
use App\Services\SalesStrategyNormalizer;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales strategy')] class extends Component {
    #[Locked]
    public int $partnerId;

    public string $objective = SalesObjective::Balanced->value;

    public string $tone = SalesTone::AiSelects->value;

    public string $researchDepth = ResearchDepth::Comprehensive->value;

    public bool $suggestAccountPricing = true;

    public bool $promoteRepeatOrdering = true;

    public bool $evaluatePharmaceuticals = true;

    public int $maxProducts = 8;

    public function mount(int $partner): void
    {
        $this->partnerId = $partner;

        if ((new SalesProspectFinder)->find($partner) === null) {
            abort(404);
        }

        $settings = SalesEngineSetting::current();
        $this->objective = $settings->objective->value;
        $this->tone = $settings->tone->value;
        $this->researchDepth = $settings->research_depth->value;
        $this->suggestAccountPricing = $settings->suggest_account_pricing;
        $this->promoteRepeatOrdering = $settings->promote_repeat_ordering;
        $this->evaluatePharmaceuticals = $settings->evaluate_pharmaceuticals;
        $this->maxProducts = $settings->max_products;
    }

    /**
     * Build a new company research and sales strategy.
     */
    public function generate(GenerateSalesStrategy $generator): void
    {
        $this->build($generator, false);
    }

    /**
     * Rewrite the strategy from the saved company research.
     */
    public function rewrite(GenerateSalesStrategy $generator): void
    {
        $this->build($generator, true);
    }

    private function build(GenerateSalesStrategy $generator, bool $reuseResearch): void
    {
        Gate::authorize('accessAdmin');

        $strategy = $generator->handle($this->partnerId, $this->persistSettings(), $reuseResearch);
        unset($this->strategy, $this->prospect);

        if ($strategy->status === SalesStrategyStatus::Failed) {
            Flux::toast(variant: 'danger', text: $strategy->failure_reason ?? __('ChatGPT could not build this sales strategy.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Sales strategy ready.'));
    }

    private function persistSettings(): SalesEngineSetting
    {
        $validated = $this->validate([
            'objective' => ['required', Rule::enum(SalesObjective::class)],
            'tone' => ['required', Rule::enum(SalesTone::class)],
            'researchDepth' => ['required', Rule::enum(ResearchDepth::class)],
            'suggestAccountPricing' => ['boolean'],
            'promoteRepeatOrdering' => ['boolean'],
            'evaluatePharmaceuticals' => ['boolean'],
            'maxProducts' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $settings = SalesEngineSetting::current();
        $settings->fill([
            'objective' => $validated['objective'],
            'tone' => $validated['tone'],
            'research_depth' => $validated['researchDepth'],
            'suggest_account_pricing' => $validated['suggestAccountPricing'],
            'promote_repeat_ordering' => $validated['promoteRepeatOrdering'],
            'evaluate_pharmaceuticals' => $validated['evaluatePharmaceuticals'],
            'max_products' => $validated['maxProducts'],
        ]);
        $settings->save();

        return $settings->refresh();
    }

    #[Computed]
    public function prospect(): SalesProspect
    {
        return (new SalesProspectFinder)->find($this->partnerId) ?? abort(404);
    }

    #[Computed]
    public function strategy(): ?SalesStrategy
    {
        return SalesStrategy::query()->where('odoo_partner_id', $this->partnerId)->first();
    }

    /**
     * @param  array<string, mixed>  $score
     * @return array<string, mixed>
     */
    public function presentScores(array $score): array
    {
        return (new SalesStrategyNormalizer)->presentScores($score);
    }

    /**
     * @return array{spend: string|null, purchases: array<int, mixed>}
     */
    #[Computed]
    public function trading(): array
    {
        $spend = $this->prospect->knownSpend;
        $purchases = [];
        $connection = OdooConnection::for(OdooCompany::MidMeds);

        if ($connection !== null && filled($connection->api_key)) {
            $catalogue = new MidMedsCatalogue;
            $client = new OdooClient($connection);

            try {
                $confirmed = $catalogue->confirmedSpend($client, $this->partnerId);

                if ($confirmed !== null) {
                    $spend = $confirmed;
                }
            } catch (\RuntimeException) {
                // Keep the spend already known from the lead review.
            }

            $purchases = $catalogue->purchaseHistory($client, $this->partnerId);
        }

        if ($purchases === []) {
            $stored = $this->strategy?->report['supplied_products'] ?? null;
            $purchases = is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
        }

        return [
            'spend' => $spend,
            'purchases' => $purchases,
        ];
    }

    /**
     * @param  array<int, mixed>  $products
     * @param  array<int, mixed>  $purchases
     * @return array<int, array<string, mixed>>
     */
    public function opportunities(array $products, array $purchases): array
    {
        $catalogue = new MidMedsCatalogue;
        $opportunities = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $name = is_string($product['name'] ?? null) ? $product['name'] : '';
            $supplied = false;

            foreach ($purchases as $purchase) {
                $purchasedName = is_array($purchase) && is_string($purchase['name'] ?? null) ? $purchase['name'] : '';

                if ($catalogue->sameProductLine($name, $purchasedName)) {
                    $supplied = true;
                    break;
                }
            }

            if (! $supplied) {
                $opportunities[] = $product;
            }
        }

        return $opportunities;
    }

    #[Computed]
    public function odooUrl(): ?string
    {
        $baseUrl = OdooConnection::for(OdooCompany::MidMeds)?->url;

        if (! is_string($baseUrl) || $baseUrl === '') {
            return null;
        }

        return rtrim($baseUrl, '/').'/web#id='.$this->partnerId.'&model=res.partner&view_type=form';
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    @php
        $prospect = $this->prospect;
        $strategy = $this->strategy;
        $report = is_array($strategy?->report) ? $strategy->report : [];
        $score = is_array($report['opportunity_score'] ?? null) ? $report['opportunity_score'] : [];
        $profile = is_array($report['company_profile'] ?? null) ? $report['company_profile'] : [];
        $financials = is_array($report['financial_research'] ?? null) ? $report['financial_research'] : [];
        $sales = is_array($report['sales_strategy'] ?? null) ? $report['sales_strategy'] : [];
        $plan = is_array($report['account_growth_plan'] ?? null) ? $report['account_growth_plan'] : [];
        $materials = is_array($report['sales_materials'] ?? null) ? $report['sales_materials'] : [];
        $next = is_array($report['next_action'] ?? null) ? $report['next_action'] : [];
        $products = is_array($report['recommended_products'] ?? null) ? $report['recommended_products'] : [];
        $trading = $this->trading;
        $opportunities = $this->opportunities($products, $trading['purchases']);
        $score = $this->presentScores($score);
        $priority = is_string($score['priority'] ?? null) ? $score['priority'] : '';
        $initialEmail = is_array($materials['initial_email'] ?? null) ? $materials['initial_email'] : [];
        $followUp = is_array($materials['follow_up_email'] ?? null) ? $materials['follow_up_email'] : [];
    @endphp

    <div class="flex flex-wrap items-center gap-3 text-sm">
        @if ($prospect->onBiggestFish)
            <flux:link :href="route('biggest-fish')" wire:navigate>{{ __('Biggest Fish') }}</flux:link>
        @endif
        @if ($prospect->onFirstOrderDiamonds)
            <flux:link :href="route('first-order-diamonds')" wire:navigate>{{ __('First Order Diamonds') }}</flux:link>
        @endif
    </div>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            @if ($prospect->logoUrl)
                <img src="{{ $prospect->logoUrl }}" alt="{{ $prospect->companyName }}" class="size-16 shrink-0 rounded-lg border border-zinc-200 bg-white object-contain dark:border-zinc-600" />
            @else
                <div class="flex size-16 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-lg font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-100">
                    {{ $prospect->initial() }}
                </div>
            @endif
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="xl">{{ $prospect->companyName }}</flux:heading>
                    @if ($prospect->existingCustomer)
                        <flux:badge color="zinc">{{ __('Existing customer') }}</flux:badge>
                    @endif
                    @if ($priority !== '')
                        <flux:badge :color="$priority === 'High' ? 'green' : ($priority === 'Low' ? 'red' : 'zinc')">{{ __($priority.' priority') }}</flux:badge>
                    @endif
                </div>
                <flux:subheading>{{ __('How MidMeds can become a preferred supplier, based on this company and the current catalogue.') }}</flux:subheading>
            </div>
        </div>
    </div>

    <flux:card class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div>
            <flux:text class="text-zinc-500">{{ __('Estimated value') }}</flux:text>
            <flux:heading>{{ $prospect->estimatedValue ?: __('Unknown') }}</flux:heading>
        </div>
        <div>
            <flux:text class="text-zinc-500">{{ __('Spent with MidMeds') }}</flux:text>
            <flux:heading>{{ $trading['spend'] ?: __('No confirmed total yet') }}</flux:heading>
        </div>
        <div>
            <flux:text class="text-zinc-500">{{ __('Company size') }}</flux:text>
            <flux:heading>{{ $prospect->companySize ?: __('Unknown') }}</flux:heading>
        </div>
        <div>
            <flux:text class="text-zinc-500">{{ __('Address') }}</flux:text>
            <flux:text>{{ $prospect->address ?: __('Unknown') }}</flux:text>
        </div>
    </flux:card>

    @if ($strategy?->status === SalesStrategyStatus::Failed)
        <flux:callout variant="danger" icon="x-circle" :heading="__('Strategy failed')" :text="$strategy->failure_reason" />
    @endif

    @if ($report !== [])
        <flux:card class="flex flex-col gap-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Prospect overview') }}</flux:heading>
                    <flux:text>{{ collect([$report['industry'] ?? null, $report['country'] ?? null])->filter(fn ($part) => is_string($part) && $part !== '')->implode(' · ') }}</flux:text>
                </div>
                <div class="text-right">
                    <flux:text class="text-zinc-500">{{ __('Sales opportunity') }}</flux:text>
                    <flux:heading size="xl">{{ (int) ($score['total'] ?? 0) }}/100</flux:heading>
                    <flux:text>{{ __('Research confidence') }}: {{ $score['research_confidence'] ?? __('Low') }}</flux:text>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <flux:text class="text-zinc-500">{{ __('Best approach') }}</flux:text>
                    <flux:heading>{{ $sales['recommended_tone'] ?: __('Not set') }}</flux:heading>
                </div>
                <div>
                    <flux:text class="text-zinc-500">{{ __('Purchase probability') }}</flux:text>
                    <flux:heading>{{ (int) ($score['purchase_probability'] ?? 0) }}/100</flux:heading>
                    @if ($score['purchase_probability_estimated'] ?? false)
                        <flux:text>{{ __('Estimated from the other scores.') }}</flux:text>
                    @endif
                </div>
                <div>
                    <flux:text class="text-zinc-500">{{ __('Account potential') }}</flux:text>
                    <flux:heading>{{ (int) ($score['account_potential'] ?? 0) }}/100</flux:heading>
                    @if ($score['account_potential_estimated'] ?? false)
                        <flux:text>{{ __('Estimated from the other scores.') }}</flux:text>
                    @endif
                </div>
            </div>

            @if ($trading['purchases'] !== [])
                <div>
                    <flux:heading>{{ __('Already supplied') }}</flux:heading>
                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        @foreach ($trading['purchases'] as $purchase)
                            @if (is_array($purchase) && filled($purchase['name'] ?? null))
                                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                    <flux:heading>{{ $purchase['name'] }}</flux:heading>
                                    <flux:text>{{ __('Ordered') }} @if (filled($purchase['quantity'] ?? null)) · {{ $purchase['quantity'] }} @endif @if (filled($purchase['spent'] ?? null)) · {{ $purchase['spent'] }} @endif</flux:text>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($opportunities !== [])
                <div>
                    <flux:heading>{{ __('Could also sell') }}</flux:heading>
                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        @foreach (array_slice($opportunities, 0, 4) as $product)
                            @if (is_array($product))
                                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="suggested-{{ $product['odoo_product_id'] ?? $loop->index }}">
                                    <flux:heading>{{ $product['name'] ?? '' }}</flux:heading>
                                    <flux:text>{{ $product['frequency'] ?: __('Frequency unknown') }}</flux:text>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @elseif ($products !== [])
                <flux:text>{{ __('The current suggestions repeat products already supplied. Generate the strategy again to look for other catalogue lines.') }}</flux:text>
            @endif

            @if (filled($report['strategy_summary'] ?? null))
                <div>
                    <flux:heading>{{ __('Recommended strategy') }}</flux:heading>
                    <flux:text class="mt-2">{{ $report['strategy_summary'] }}</flux:text>
                </div>
            @endif

            @if (filled($report['opening_angle'] ?? null))
                <flux:callout :heading="__('Suggested opening angle')" :text="$report['opening_angle']" />
            @endif

            @if (filled($score['recurring_revenue_scenario'] ?? null))
                <div>
                    <flux:heading>{{ __('Recurring revenue scenario') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Scenario, not a forecast.') }} {{ $score['recurring_revenue_scenario'] }}</flux:text>
                </div>
            @endif

            @if ($strategy?->generated_at)
                <flux:text class="text-zinc-500">{{ __('Generated :date', ['date' => $strategy->generated_at->timezone(config('app.timezone'))->format('j M Y H:i')]) }}</flux:text>
            @endif
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Company profile') }}</flux:heading>
            @if (filled($report['executive_summary'] ?? null))
                <flux:text>{{ $report['executive_summary'] }}</flux:text>
            @endif
            <dl class="grid gap-3 text-sm md:grid-cols-2">
                @foreach ([
                    __('Activities') => $profile['activities'] ?? '',
                    __('Sites') => $profile['sites'] ?? '',
                    __('Employees') => $profile['employees'] ?? '',
                    __('Headquarters') => $profile['headquarters'] ?? '',
                    __('Ownership') => $profile['ownership'] ?? '',
                    __('Revenue') => $financials['revenue'] ?? '',
                    __('Profitability') => $financials['profitability'] ?? '',
                    __('Valuation') => $financials['valuation'] ?? '',
                ] as $label => $value)
                    @if (is_string($value) && $value !== '')
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                            <dd>{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
            @if (filled($financials['notes'] ?? null))
                <flux:text>{{ $financials['notes'] }}</flux:text>
            @endif
            @if (filled($report['reason_to_contact'] ?? null))
                <div>
                    <flux:heading>{{ __('Why contact them now') }}</flux:heading>
                    <flux:text class="mt-2">{{ $report['reason_to_contact'] }}</flux:text>
                </div>
            @endif
        </flux:card>

        @if ($opportunities !== [])
            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Could also sell') }}</flux:heading>
                <flux:text>{{ __('These are other MidMeds catalogue lines that fit what the company does. They are not another pack or size of a product already supplied. Pharmaceutical supply still needs eligibility checks and account approval.') }}</flux:text>
                <div class="grid gap-4">
                    @foreach ($opportunities as $product)
                        @if (is_array($product))
                            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="product-{{ $product['odoo_product_id'] ?? $loop->index }}">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <flux:heading>{{ $product['name'] ?? '' }}</flux:heading>
                                    <flux:badge color="zinc">{{ match ($product['group'] ?? '') {
                                        'recurring' => __('Recurring'),
                                        'equipment' => __('Equipment'),
                                        'expansion' => __('Later expansion'),
                                        default => __('Immediate'),
                                    } }}</flux:badge>
                                </div>
                                <flux:text class="mt-1">
                                    {{ ($product['sku'] ?? '') !== '' ? $product['sku'] : __('No SKU') }}
                                    @if (filled($product['list_price'] ?? null))
                                        · {{ $product['list_price'] }}
                                    @endif
                                    @if (is_numeric($product['available'] ?? null))
                                        · {{ __('Available: :count', ['count' => rtrim(rtrim(number_format((float) $product['available'], 2), '0'), '.')]) }}
                                    @endif
                                </flux:text>
                                @if (filled($product['use'] ?? null))
                                    <flux:text class="mt-2">{{ $product['use'] }}</flux:text>
                                @endif
                                @if (filled($product['reason'] ?? null))
                                    <flux:text class="mt-1">{{ $product['reason'] }}</flux:text>
                                @endif
                                <flux:text class="mt-2 text-zinc-500">{{ __('Confidence') }}: {{ $product['confidence'] ?? __('Low') }} · {{ $product['frequency'] ?: __('Frequency unknown') }}</flux:text>
                            </div>
                        @endif
                    @endforeach
                </div>
            </flux:card>
        @endif

        <div class="grid gap-4 lg:grid-cols-2">
            <flux:card class="flex flex-col gap-3">
                <flux:heading size="lg">{{ __('Opportunity score') }}</flux:heading>
                @foreach ([
                    __('Product fit') => $score['product_fit'] ?? 0,
                    __('Repeat purchasing') => $score['repeat_purchasing'] ?? 0,
                    __('Addressable spend') => $score['addressable_spend'] ?? 0,
                    __('Win probability') => $score['win_probability'] ?? 0,
                    __('Strategic value') => $score['strategic_value'] ?? 0,
                ] as $label => $value)
                    <div class="flex items-center justify-between text-sm">
                        <span>{{ $label }}</span>
                        <span>{{ (int) $value }}/100</span>
                    </div>
                @endforeach
                @if (filled($score['rationale'] ?? null))
                    <flux:text>{{ $score['rationale'] }}</flux:text>
                @endif
            </flux:card>

            <flux:card class="flex flex-col gap-3">
                <flux:heading size="lg">{{ __('Sales approach') }}</flux:heading>
                @foreach ($sales['buying_motivations'] ?? [] as $motivation)
                    @if (is_string($motivation))
                        <flux:text>{{ $motivation }}</flux:text>
                    @endif
                @endforeach
                @foreach ($sales['tactics'] ?? [] as $tactic)
                    @if (is_array($tactic) && filled($tactic['name'] ?? null))
                        <div>
                            <flux:heading>{{ $tactic['name'] }}</flux:heading>
                            <flux:text>{{ $tactic['detail'] ?? '' }}</flux:text>
                        </div>
                    @endif
                @endforeach
                @if (($sales['decision_makers'] ?? []) !== [])
                    <flux:text>{{ __('Decision-maker roles') }}: {{ implode(', ', array_filter($sales['decision_makers'], 'is_string')) }}</flux:text>
                @endif
            </flux:card>
        </div>

        @if (($sales['brand_strengths'] ?? []) !== [])
            <flux:card class="flex flex-col gap-3">
                <flux:heading size="lg">{{ __('MidMeds strengths to emphasise') }}</flux:heading>
                @foreach ($sales['brand_strengths'] as $strength)
                    @if (is_array($strength) && filled($strength['name'] ?? null))
                        <div>
                            <flux:heading>{{ $strength['name'] }}</flux:heading>
                            <flux:text>{{ $strength['detail'] ?? '' }}</flux:text>
                        </div>
                    @endif
                @endforeach
            </flux:card>
        @endif

        <flux:card class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Account plan') }}</flux:heading>
            @foreach ([
                __('Initial engagement') => $plan['initial_engagement'] ?? '',
                __('First order') => $plan['first_order'] ?? '',
                __('Regular purchasing') => $plan['regular_purchasing'] ?? '',
                __('Account expansion') => $plan['expansion'] ?? '',
            ] as $label => $value)
                @if (is_string($value) && $value !== '')
                    <div>
                        <flux:heading>{{ $label }}</flux:heading>
                        <flux:text class="mt-1">{{ $value }}</flux:text>
                    </div>
                @endif
            @endforeach
            @if (($plan['discovery_questions'] ?? []) !== [])
                <div>
                    <flux:heading>{{ __('Discovery questions') }}</flux:heading>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                        @foreach ($plan['discovery_questions'] as $question)
                            @if (is_string($question))
                                <li>{{ $question }}</li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endif
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Sales materials') }}</flux:heading>
            @if (filled($initialEmail['subject'] ?? null) || filled($initialEmail['body'] ?? null))
                <div>
                    <flux:heading>{{ __('Email') }}</flux:heading>
                    @if (filled($initialEmail['subject'] ?? null))
                        <flux:text class="mt-1 font-medium">{{ $initialEmail['subject'] }}</flux:text>
                    @endif
                    <flux:text class="mt-2 whitespace-pre-line">{{ $initialEmail['body'] ?? '' }}</flux:text>
                </div>
            @endif
            @if (filled($followUp['body'] ?? null))
                <div>
                    <flux:heading>{{ __('Follow-up') }}</flux:heading>
                    @if (filled($followUp['subject'] ?? null))
                        <flux:text class="mt-1 font-medium">{{ $followUp['subject'] }}</flux:text>
                    @endif
                    <flux:text class="mt-2 whitespace-pre-line">{{ $followUp['body'] }}</flux:text>
                </div>
            @endif
            @if (filled($materials['telephone_script'] ?? null))
                <div>
                    <flux:heading>{{ __('Telephone opening') }}</flux:heading>
                    <flux:text class="mt-2 whitespace-pre-line">{{ $materials['telephone_script'] }}</flux:text>
                </div>
            @endif
            @if (filled($materials['linkedin_message'] ?? null))
                <div>
                    <flux:heading>{{ __('LinkedIn') }}</flux:heading>
                    <flux:text class="mt-2 whitespace-pre-line">{{ $materials['linkedin_message'] }}</flux:text>
                </div>
            @endif
            @if (filled($materials['strategic_pitch'] ?? null))
                <div>
                    <flux:heading>{{ __('Long-term supply pitch') }}</flux:heading>
                    <flux:text class="mt-2">{{ $materials['strategic_pitch'] }}</flux:text>
                </div>
            @endif
            @foreach ($materials['objection_responses'] ?? [] as $objection)
                @if (is_array($objection) && filled($objection['name'] ?? null))
                    <div>
                        <flux:heading>{{ $objection['name'] }}</flux:heading>
                        <flux:text>{{ $objection['detail'] ?? '' }}</flux:text>
                    </div>
                @endif
            @endforeach
        </flux:card>

        <flux:card class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Next action') }}</flux:heading>
            @foreach ([
                __('Strongest opportunity') => $next['strongest_opportunity'] ?? '',
                __('Decision-maker role') => $next['decision_maker'] ?? '',
                __('Channel') => $next['channel'] ?? '',
                __('Opening proposition') => $next['opening_proposition'] ?? '',
                __('First products') => $next['first_products'] ?? '',
                __('Next step') => $next['next_step'] ?? '',
            ] as $label => $value)
                @if (is_string($value) && $value !== '')
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                        <dd>{{ $value }}</dd>
                    </div>
                @endif
            @endforeach
            @foreach ($next['missing_facts'] ?? [] as $gap)
                @if (is_string($gap))
                    <flux:text>{{ $gap }}</flux:text>
                @endif
            @endforeach
        </flux:card>

        @if (($report['sources'] ?? []) !== [] || ($report['information_gaps'] ?? []) !== [])
            <flux:card class="flex flex-col gap-3">
                <flux:heading size="lg">{{ __('Sources and gaps') }}</flux:heading>
                @foreach ($report['sources'] ?? [] as $source)
                    @if (is_array($source) && filled($source['url'] ?? null))
                        <flux:link :href="$source['url']" target="_blank" rel="noopener noreferrer">{{ $source['title'] ?? $source['url'] }}</flux:link>
                    @endif
                @endforeach
                @foreach ($report['information_gaps'] ?? [] as $gap)
                    @if (is_string($gap))
                        <flux:text>{{ $gap }}</flux:text>
                    @endif
                @endforeach
            </flux:card>
        @endif
    @else
        <flux:text>{{ __('No sales strategy has been generated yet.') }}</flux:text>
    @endif

    @if (auth()->user()->isAdmin())
        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Sales engine') }}</flux:heading>
                <flux:text>{{ __('These choices are saved and used the next time a strategy is generated.') }}</flux:text>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:select wire:model="objective" :label="__('Primary sales objective')">
                    @foreach (SalesObjective::cases() as $salesObjective)
                        <flux:select.option value="{{ $salesObjective->value }}">{{ $salesObjective->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="tone" :label="__('Sales tone')">
                    @foreach (SalesTone::cases() as $salesTone)
                        <flux:select.option value="{{ $salesTone->value }}">{{ $salesTone->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="researchDepth" :label="__('Research depth')">
                    @foreach (ResearchDepth::cases() as $depth)
                        <flux:select.option value="{{ $depth->value }}">{{ $depth->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:field>
                    <flux:label>{{ __('Maximum product recommendations') }}</flux:label>
                    <flux:input wire:model="maxProducts" type="number" min="1" max="10" />
                    <flux:error name="maxProducts" />
                </flux:field>
            </div>

            <div class="flex flex-col gap-2">
                <flux:checkbox wire:model="suggestAccountPricing" :label="__('Suggest negotiated account pricing')" />
                <flux:checkbox wire:model="promoteRepeatOrdering" :label="__('Promote repeat ordering')" />
                <flux:checkbox wire:model="evaluatePharmaceuticals" :label="__('Evaluate eligible pharmaceutical opportunities')" />
            </div>

            <div class="flex flex-wrap gap-3">
                <flux:button wire:click="generate" icon="sparkles" variant="primary" data-test="generate-sales-strategy">
                    {{ __('Generate sales strategy') }}
                </flux:button>
                @if (is_array($strategy?->research) && $strategy->research !== [])
                    <flux:button wire:click="rewrite" icon="arrow-path" data-test="rewrite-sales-strategy">
                        {{ __('Rewrite pitches') }}
                    </flux:button>
                @endif
            </div>
            <flux:text wire:loading>{{ __('Generating the sales strategy. This can take a minute.') }}</flux:text>
        </flux:card>
    @endif
</div>
