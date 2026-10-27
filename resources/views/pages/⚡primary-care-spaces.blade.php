<?php

use App\Actions\ResearchGpPremises;
use App\Enums\GpDevelopmentType;
use App\Enums\GpGeography;
use App\Enums\GpLeadStatus;
use App\Enums\GpSalesPriority;
use App\Models\GpPremisesLead;
use App\Models\GpPremisesSetting;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Primary Care Spaces')] class extends Component {
    use WithPagination;

    public string $geography = GpGeography::UnitedKingdom->value;

    public int $sourceWindowMonths = 12;

    public int $futureHorizonMonths = 24;

    public bool $includeNewBuildings = true;

    public bool $includeExtensions = true;

    public bool $includeRefurbishments = true;

    public bool $includeClinicalRooms = true;

    public bool $includeRelocations = true;

    public string $highPriorityOnly = '0';

    public function mount(): void
    {
        $settings = GpPremisesSetting::current();
        $this->geography = $settings->geography->value;
        $this->sourceWindowMonths = $settings->source_window_months;
        $this->futureHorizonMonths = $settings->future_horizon_months;
        $this->highPriorityOnly = $settings->high_priority_only ? '1' : '0';
        $selected = array_map(fn (GpDevelopmentType $type): string => $type->value, $settings->selectedTypes());
        $this->includeNewBuildings = in_array(GpDevelopmentType::NewBuilding->value, $selected, true);
        $this->includeExtensions = in_array(GpDevelopmentType::Extension->value, $selected, true);
        $this->includeRefurbishments = in_array(GpDevelopmentType::Refurbishment->value, $selected, true);
        $this->includeClinicalRooms = in_array(GpDevelopmentType::ClinicalRooms->value, $selected, true);
        $this->includeRelocations = in_array(GpDevelopmentType::Relocation->value, $selected, true);
    }

    /**
     * Save the search settings and look for GP premises projects.
     */
    public function run(ResearchGpPremises $research): void
    {
        Gate::authorize('accessAdmin');

        $settings = $this->persistSettings();

        if (! $settings instanceof GpPremisesSetting) {
            return;
        }

        $result = $research->discover($settings);
        $this->resetPage();
        unset($this->leads, $this->watchlist, $this->closed, $this->settings);

        if ($result->skippedReason !== null) {
            Flux::toast(variant: 'danger', text: $result->skippedReason);

            return;
        }

        Flux::toast(variant: 'success', text: trans_choice(
            'Added :count GP premises project.|Added :count GP premises projects.',
            $result->added,
            ['count' => $result->added],
        ));
    }

    private function persistSettings(): ?GpPremisesSetting
    {
        $validated = $this->validate([
            'geography' => ['required', Rule::enum(GpGeography::class)],
            'sourceWindowMonths' => ['required', 'integer', Rule::in([3, 6, 12, 24])],
            'futureHorizonMonths' => ['required', 'integer', Rule::in([12, 24, 36])],
            'includeNewBuildings' => ['boolean'],
            'includeExtensions' => ['boolean'],
            'includeRefurbishments' => ['boolean'],
            'includeClinicalRooms' => ['boolean'],
            'includeRelocations' => ['boolean'],
            'highPriorityOnly' => ['required', Rule::in(['0', '1'])],
        ]);

        $types = array_keys(array_filter([
            GpDevelopmentType::NewBuilding->value => $validated['includeNewBuildings'],
            GpDevelopmentType::Extension->value => $validated['includeExtensions'],
            GpDevelopmentType::Refurbishment->value => $validated['includeRefurbishments'],
            GpDevelopmentType::ClinicalRooms->value => $validated['includeClinicalRooms'],
            GpDevelopmentType::Relocation->value => $validated['includeRelocations'],
        ]));

        if ($types === []) {
            $this->addError('includeNewBuildings', __('Choose at least one development type.'));

            return null;
        }

        $settings = GpPremisesSetting::current();
        $settings->fill([
            'geography' => $validated['geography'],
            'source_window_months' => $validated['sourceWindowMonths'],
            'future_horizon_months' => $validated['futureHorizonMonths'],
            'development_types' => $types,
            'high_priority_only' => $validated['highPriorityOnly'] === '1',
        ]);
        $settings->save();

        return $settings->refresh();
    }

    /**
     * @return Builder<GpPremisesLead>
     */
    private function visible(GpLeadStatus $status): Builder
    {
        $settings = $this->settings;

        return GpPremisesLead::query()
            ->where('status', $status)
            ->when(
                $settings->geography !== GpGeography::UnitedKingdom,
                fn (Builder $query) => $query->where('nation', $settings->geography),
            )
            ->whereIn('development_type', array_map(
                fn (GpDevelopmentType $type): string => $type->value,
                $settings->selectedTypes(),
            ))
            ->when(
                $status === GpLeadStatus::Active && $settings->high_priority_only,
                fn (Builder $query) => $query->where('sales_priority', GpSalesPriority::High),
            )
            ->orderByDesc('opportunity_score')
            ->orderBy('stage_rank');
    }

    /**
     * @return LengthAwarePaginator<int, GpPremisesLead>
     */
    #[Computed]
    public function leads(): LengthAwarePaginator
    {
        return $this->visible(GpLeadStatus::Active)->paginate(15);
    }

    /**
     * @return Collection<int, GpPremisesLead>
     */
    #[Computed]
    public function watchlist(): Collection
    {
        return $this->visible(GpLeadStatus::Watchlist)->limit(20)->get();
    }

    /**
     * @return Collection<int, GpPremisesLead>
     */
    #[Computed]
    public function closed(): Collection
    {
        return $this->visible(GpLeadStatus::Inactive)->limit(20)->get();
    }

    #[Computed]
    public function settings(): GpPremisesSetting
    {
        return GpPremisesSetting::current();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Primary Care Spaces') }}</flux:heading>
            <flux:subheading>{{ __('GP surgeries that are planning, funding, extending, refurbishing or building premises. Projects stay in the pipeline and are checked again as they move towards fit-out.') }}</flux:subheading>
        </div>
    </div>

    @if ($this->leads->isEmpty() && $this->watchlist->isEmpty() && $this->closed->isEmpty())
        <flux:text>{{ __('No GP premises projects have been found yet.') }}</flux:text>
    @endif

    @if ($this->leads->isNotEmpty())
        <div class="grid gap-4">
            @foreach ($this->leads as $lead)
                <flux:card wire:key="gp-lead-{{ $lead->id }}" class="flex flex-col gap-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:heading>
                                    <flux:link :href="route('primary-care-spaces.show', $lead)" wire:navigate>{{ $lead->surgery_name }}</flux:link>
                                </flux:heading>
                                <flux:badge :color="$lead->sales_priority === GpSalesPriority::High ? 'green' : ($lead->sales_priority === GpSalesPriority::Low ? 'red' : 'zinc')">
                                    {{ $lead->sales_priority->label() }}
                                </flux:badge>
                                <flux:badge color="zinc">{{ $lead->project_stage->groupLabel() }}</flux:badge>
                            </div>
                            <flux:text class="mt-1">{{ $lead->locationLabel() }}</flux:text>
                        </div>
                        <div class="text-right">
                            <flux:text class="text-zinc-500">{{ __('Opportunity') }}</flux:text>
                            <flux:heading>{{ $lead->opportunity_score }}/100</flux:heading>
                        </div>
                    </div>

                    <div>
                        <flux:heading>{{ $lead->development_type->label() }}</flux:heading>
                        @if (filled($lead->development_details))
                            <flux:text class="mt-1">{{ $lead->development_details }}</flux:text>
                        @endif
                    </div>

                    <dl class="grid gap-3 text-sm md:grid-cols-3">
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</dt>
                            <dd>{{ $lead->project_stage->label() }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Source published') }}</dt>
                            <dd>{{ $lead->source_published_on ?: __('Unknown') }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Sales timing') }}</dt>
                            <dd>{{ $lead->sales_timing ?: __('Not set') }}</dd>
                        </div>
                    </dl>

                    @if (filled($lead->fit_out_opportunity))
                        <flux:callout :heading="__('Possible fit-out')" :text="$lead->fit_out_opportunity" />
                    @endif

                    <div class="flex flex-wrap gap-4">
                        <flux:link :href="route('primary-care-spaces.show', $lead)" wire:navigate>{{ __('Project details') }}</flux:link>
                        @if ($lead->source_url)
                            <flux:link :href="$lead->source_url" target="_blank" rel="noopener noreferrer">{{ $lead->source_title ?: __('Source') }}</flux:link>
                        @endif
                        @if ($lead->website)
                            <flux:link :href="$lead->website" target="_blank" rel="noopener noreferrer">{{ __('Surgery website') }}</flux:link>
                        @endif
                    </div>
                </flux:card>
            @endforeach
        </div>
        <flux:pagination :paginator="$this->leads" />
    @endif

    @if ($this->watchlist->isNotEmpty())
        <div class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Research watchlist') }}</flux:heading>
            <flux:text>{{ __('These projects are not confirmed sales leads yet.') }}</flux:text>
            @foreach ($this->watchlist as $lead)
                <flux:card wire:key="gp-watch-{{ $lead->id }}">
                    <flux:link :href="route('primary-care-spaces.show', $lead)" wire:navigate>{{ $lead->surgery_name }}</flux:link>
                    <flux:text>{{ $lead->locationLabel() }} · {{ $lead->development_details ?: $lead->project_stage->label() }}</flux:text>
                </flux:card>
            @endforeach
        </div>
    @endif

    @if ($this->closed->isNotEmpty())
        <div class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Completed and cancelled') }}</flux:heading>
            @foreach ($this->closed as $lead)
                <flux:card wire:key="gp-closed-{{ $lead->id }}">
                    <flux:link :href="route('primary-care-spaces.show', $lead)" wire:navigate>{{ $lead->surgery_name }}</flux:link>
                    <flux:text>{{ $lead->project_stage->label() }} · {{ $lead->locationLabel() }}</flux:text>
                </flux:card>
            @endforeach
        </div>
    @endif

    @if (filled($this->settings->last_limitations))
        <flux:text>{{ $this->settings->last_limitations }}</flux:text>
    @endif

    @if (auth()->user()->isAdmin())
        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('GP development lead search') }}</flux:heading>
                <flux:text>{{ __('A daily check updates projects already found. Run a search to look for more.') }}</flux:text>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <flux:select wire:model="geography" :label="__('Geographic coverage')">
                    @foreach (GpGeography::cases() as $area)
                        <flux:select.option value="{{ $area->value }}">{{ $area->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="sourceWindowMonths" :label="__('Sources published within')">
                    <flux:select.option value="3">{{ __('Last 3 months') }}</flux:select.option>
                    <flux:select.option value="6">{{ __('Last 6 months') }}</flux:select.option>
                    <flux:select.option value="12">{{ __('Last 12 months') }}</flux:select.option>
                    <flux:select.option value="24">{{ __('Last 24 months') }}</flux:select.option>
                </flux:select>
                <flux:select wire:model="futureHorizonMonths" :label="__('Future projects within')">
                    <flux:select.option value="12">{{ __('12 months') }}</flux:select.option>
                    <flux:select.option value="24">{{ __('24 months') }}</flux:select.option>
                    <flux:select.option value="36">{{ __('36 months') }}</flux:select.option>
                </flux:select>
                <flux:select wire:model="highPriorityOnly" :label="__('Sales priority')">
                    <flux:select.option value="0">{{ __('All verified active opportunities') }}</flux:select.option>
                    <flux:select.option value="1">{{ __('High-priority opportunities only') }}</flux:select.option>
                </flux:select>
            </div>
            <div class="flex flex-col gap-2">
                <flux:checkbox wire:model="includeNewBuildings" :label="__('New surgery buildings')" />
                <flux:checkbox wire:model="includeExtensions" :label="__('Extensions')" />
                <flux:checkbox wire:model="includeRefurbishments" :label="__('Refurbishments')" />
                <flux:checkbox wire:model="includeClinicalRooms" :label="__('New clinical rooms and conversions')" />
                <flux:checkbox wire:model="includeRelocations" :label="__('Surgery relocations')" />
                <flux:error name="includeNewBuildings" />
            </div>
            <flux:button wire:click="run" icon="magnifying-glass" variant="primary" data-test="run-gp-research">
                {{ __('Run research') }}
            </flux:button>
            <flux:text wire:loading>{{ __('Searching public NHS, planning and news sources. This can take a minute.') }}</flux:text>
        </flux:card>
    @endif
</div>
