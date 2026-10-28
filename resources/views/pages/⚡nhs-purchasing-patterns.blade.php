<?php

use App\Actions\ResearchNhsPurchasing;
use App\Enums\NhsDemandDirection;
use App\Models\NhsCatalogueGap;
use App\Models\NhsDirective;
use App\Models\NhsProductOpportunity;
use App\Models\NhsPurchasingSetting;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('NHS Purchasing Patterns')] class extends Component {
    use WithPagination;

    public string $view = 'all';

    public function updatedView(string $value): void
    {
        if (! in_array($value, ['all', 'growth', 'decline'], true)) {
            $this->view = 'all';
        }

        $this->resetPage();
    }

    /**
     * Look for NHS directives and compare them with the Dene Healthcare catalogue.
     */
    public function run(ResearchNhsPurchasing $research): void
    {
        Gate::authorize('accessAdmin');

        $result = $research->handle();
        $this->resetPage();
        unset($this->opportunities, $this->outlook, $this->directives, $this->counts, $this->settings);

        if ($result->skippedReason !== null) {
            Flux::toast(variant: 'danger', text: $result->skippedReason);

            return;
        }

        Flux::toast(variant: 'success', text: trans_choice(
            'Updated :count purchasing pattern.|Updated :count purchasing patterns.',
            $result->opportunities,
            ['count' => $result->opportunities],
        ));
    }

    /**
     * Remove the saved NHS research and check the sources again.
     */
    public function refreshResearch(ResearchNhsPurchasing $research): void
    {
        Gate::authorize('accessAdmin');

        $this->deleteResearch();
        $this->forgetResearchView();
        $this->run($research);
    }

    /**
     * Remove every saved directive, purchasing pattern, and research summary.
     */
    public function clearResearch(): void
    {
        Gate::authorize('accessAdmin');

        $this->deleteResearch();
        $this->forgetResearchView();

        Flux::toast(variant: 'success', text: __('NHS research removed.'));
    }

    /**
     * Remove one purchasing pattern.
     */
    public function removeOpportunity(int $opportunityId): void
    {
        Gate::authorize('accessAdmin');

        NhsProductOpportunity::query()->whereKey($opportunityId)->delete();
        $this->forgetResearchView();

        Flux::toast(variant: 'success', text: __('Purchasing pattern removed.'));
    }

    /**
     * Remove one directive.
     */
    public function removeDirective(int $directiveId): void
    {
        Gate::authorize('accessAdmin');

        NhsDirective::query()->whereKey($directiveId)->delete();
        $this->forgetResearchView();

        Flux::toast(variant: 'success', text: __('Directive removed.'));
    }

    private function deleteResearch(): void
    {
        NhsCatalogueGap::query()->delete();
        NhsDirective::query()->delete();
        NhsProductOpportunity::query()->delete();

        $settings = NhsPurchasingSetting::current();
        $settings->last_summary = null;
        $settings->last_changes = null;
        $settings->last_limitations = null;
        $settings->last_ran_at = null;
        $settings->save();
    }

    private function forgetResearchView(): void
    {
        $this->resetPage();
        unset($this->opportunities, $this->outlook, $this->directives, $this->counts, $this->settings);
    }

    /**
     * @return array{opportunities: int, growth: int, risks: int}
     */
    #[Computed]
    public function counts(): array
    {
        return [
            'opportunities' => NhsProductOpportunity::query()->count(),
            'growth' => NhsProductOpportunity::query()->whereIn('demand_direction', [
                NhsDemandDirection::StrongIncrease,
                NhsDemandDirection::ModerateIncrease,
            ])->count(),
            'risks' => NhsProductOpportunity::query()->whereIn('demand_direction', [
                NhsDemandDirection::StrongDecrease,
                NhsDemandDirection::ModerateDecrease,
            ])->count(),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, NhsProductOpportunity>
     */
    #[Computed]
    public function opportunities(): LengthAwarePaginator
    {
        return NhsProductOpportunity::query()
            ->with('gap')
            ->when($this->view === 'growth', fn (Builder $query) => $query->whereIn('demand_direction', [
                NhsDemandDirection::StrongIncrease,
                NhsDemandDirection::ModerateIncrease,
            ]))
            ->when($this->view === 'decline', fn (Builder $query) => $query->whereIn('demand_direction', [
                NhsDemandDirection::StrongDecrease,
                NhsDemandDirection::ModerateDecrease,
            ]))
            ->orderByDesc('opportunity_score')
            ->orderBy('name')
            ->paginate(15);
    }

    /**
     * @return Collection<int, NhsProductOpportunity>
     */
    #[Computed]
    public function outlook(): Collection
    {
        return NhsProductOpportunity::query()
            ->when($this->view === 'growth', fn (Builder $query) => $query->whereIn('demand_direction', [
                NhsDemandDirection::StrongIncrease,
                NhsDemandDirection::ModerateIncrease,
            ]))
            ->when($this->view === 'decline', fn (Builder $query) => $query->whereIn('demand_direction', [
                NhsDemandDirection::StrongDecrease,
                NhsDemandDirection::ModerateDecrease,
            ]))
            ->get()
            ->sort(function (NhsProductOpportunity $left, NhsProductOpportunity $right): int {
                $rank = $left->demand_direction->outlookOrder() <=> $right->demand_direction->outlookOrder();

                if ($rank !== 0) {
                    return $rank;
                }

                $score = $right->opportunity_score <=> $left->opportunity_score;

                if ($score !== 0) {
                    return $score;
                }

                return $left->name <=> $right->name;
            })
            ->values();
    }

    /**
     * @return Collection<int, NhsDirective>
     */
    #[Computed]
    public function directives(): Collection
    {
        return NhsDirective::query()->latest('last_verified_at')->limit(8)->get();
    }

    #[Computed]
    public function settings(): NhsPurchasingSetting
    {
        return NhsPurchasingSetting::current();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('NHS Purchasing Patterns') }}</flux:heading>
            <flux:subheading>{{ __('NHS directives and guidance, translated into predicted GP purchasing changes for Dene Healthcare. A weekly check keeps the record and updates it.') }}</flux:subheading>
        </div>
    </div>

    @if ($this->outlook->isNotEmpty())
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Product category') }}</flux:table.column>
                <flux:table.column>{{ __('Expected direction') }}</flux:table.column>
                <flux:table.column>{{ __('Rationale') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->outlook as $row)
                    <flux:table.row wire:key="nhs-outlook-{{ $row->id }}">
                        <flux:table.cell variant="strong">{{ $row->name }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($row->demand_direction->isGrowth())
                                <span class="inline-flex items-center gap-1.5 font-medium text-green-600 dark:text-green-400">↗ {{ $row->demand_direction->outlookLabel() }}</span>
                            @elseif ($row->demand_direction->isDecline())
                                <span class="inline-flex items-center gap-1.5 font-medium text-orange-600 dark:text-orange-400">– {{ $row->demand_direction->outlookLabel() }}</span>
                            @else
                                <span class="font-medium text-zinc-500">{{ $row->demand_direction->outlookLabel() }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-normal">{{ $row->outlookRationale() }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        <flux:text>{{ __('This is a predicted purchasing effect from the latest NHS source check. It is not evidence that spending has already changed, and it is not a confirmed order.') }}</flux:text>
    @endif

    @if (filled($this->settings->last_summary))
        <flux:callout :heading="__('Research summary')" :text="$this->settings->last_summary" />
    @endif

    @if ($this->counts['opportunities'] === 0 && $this->directives->isEmpty())
        <flux:text>{{ __('No NHS purchasing patterns have been found yet.') }}</flux:text>
    @endif

    @if ($this->counts['opportunities'] > 0)
        <div class="grid gap-4 md:grid-cols-3">
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Opportunities') }}</flux:text>
                <flux:heading size="xl">{{ $this->counts['opportunities'] }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Growth signals') }}</flux:text>
                <flux:heading size="xl">{{ $this->counts['growth'] }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Demand risks') }}</flux:text>
                <flux:heading size="xl">{{ $this->counts['risks'] }}</flux:heading>
            </flux:card>
        </div>

        <flux:select wire:model.live="view" :label="__('Show')">
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            <flux:select.option value="growth">{{ __('Growth') }}</flux:select.option>
            <flux:select.option value="decline">{{ __('Decline') }}</flux:select.option>
        </flux:select>

        @if ($this->opportunities->isEmpty())
            <flux:text>{{ __('No purchasing patterns match this view.') }}</flux:text>
        @else
            <div class="grid gap-4">
                @foreach ($this->opportunities as $opportunity)
                    <flux:card wire:key="nhs-opportunity-{{ $opportunity->id }}" class="flex flex-col gap-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:heading>
                                        <flux:link :href="route('nhs-purchasing-patterns.show', $opportunity)" wire:navigate>{{ $opportunity->name }}</flux:link>
                                    </flux:heading>
                                    <flux:badge :color="$opportunity->demand_direction->isGrowth() ? 'green' : ($opportunity->demand_direction->isDecline() ? 'red' : 'zinc')">
                                        {{ $opportunity->directionLabel() }}
                                    </flux:badge>
                                    @if ($opportunity->gap)
                                        <flux:badge color="zinc">{{ $opportunity->gap->coverage->label() }}</flux:badge>
                                    @endif
                                </div>
                                <flux:text class="mt-1">{{ $opportunity->category ?: __('Uncategorised') }}</flux:text>
                            </div>
                            <div class="flex flex-col items-end gap-2">
                                <div class="text-right">
                                    <flux:text class="text-zinc-500">{{ $opportunity->priorityLabel() }}</flux:text>
                                    <flux:heading>{{ $opportunity->opportunity_score }}/100</flux:heading>
                                </div>
                                @if (auth()->user()->isAdmin())
                                    <flux:button
                                        type="button"
                                        variant="ghost"
                                        icon="x-circle"
                                        wire:click="removeOpportunity({{ $opportunity->id }})"
                                        wire:confirm="{{ __('Remove this purchasing pattern?') }}"
                                        data-test="remove-nhs-opportunity-{{ $opportunity->id }}"
                                    >
                                        {{ __('Remove') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                        @if (filled($opportunity->explanation))
                            <flux:text>{{ $opportunity->explanation }}</flux:text>
                        @endif
                        <flux:text>
                            {{ $opportunity->requirement_confirmed
                                ? __('A binding or contractual source applies to the organisations listed. This is still not a confirmed customer order.')
                                : __('Predicted commercial opportunity. This is not confirmation that a GP practice must buy this product.') }}
                        </flux:text>
                        <flux:link :href="route('nhs-purchasing-patterns.show', $opportunity)" wire:navigate>{{ __('View pattern') }}</flux:link>
                    </flux:card>
                @endforeach
            </div>
            <flux:pagination :paginator="$this->opportunities" />
        @endif
    @endif

    @if ($this->directives->isNotEmpty())
        <div class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Recent directives') }}</flux:heading>
            @foreach ($this->directives as $directive)
                <flux:card wire:key="nhs-directive-{{ $directive->id }}" class="flex flex-col gap-2">
                    <flux:heading>
                        <flux:link :href="route('nhs-purchasing-patterns.directives.show', $directive)" wire:navigate>{{ $directive->title }}</flux:link>
                    </flux:heading>
                    <flux:text>{{ collect([$directive->issuer, $directive->published_on, $directive->obligation->label()])->filter()->implode(' · ') }}</flux:text>
                    @if (filled($directive->summary))
                        <flux:text>{{ $directive->summary }}</flux:text>
                    @endif
                    @if (filled($directive->commercial_implications))
                        <flux:text>{{ $directive->commercial_implications }}</flux:text>
                    @endif
                    @if ($directive->source_url)
                        <flux:link :href="$directive->source_url" target="_blank" rel="noopener noreferrer">{{ $directive->source_title ?: __('Source') }}</flux:link>
                    @else
                        <flux:text>{{ __('Source link not found') }}</flux:text>
                    @endif
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:link :href="route('nhs-purchasing-patterns.directives.show', $directive)" wire:navigate>{{ __('View purchasing impact') }}</flux:link>
                        @if (auth()->user()->isAdmin())
                            <flux:button
                                type="button"
                                variant="ghost"
                                icon="x-circle"
                                wire:click="removeDirective({{ $directive->id }})"
                                wire:confirm="{{ __('Remove this directive?') }}"
                                data-test="remove-nhs-directive-{{ $directive->id }}"
                            >
                                {{ __('Remove') }}
                            </flux:button>
                        @endif
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif

    @if (filled($this->settings->last_changes))
        <div>
            <flux:heading>{{ __('What changed') }}</flux:heading>
            <flux:text class="mt-1">{{ $this->settings->last_changes }}</flux:text>
        </div>
    @endif

    @if (filled($this->settings->last_limitations))
        <flux:text>{{ $this->settings->last_limitations }}</flux:text>
    @endif

    @if (auth()->user()->isAdmin())
        <flux:card class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Check NHS sources') }}</flux:heading>
            <flux:text>{{ __('A weekly check looks for new directives, updates the purchasing forecast, and compares it with the Dene Healthcare catalogue.') }}</flux:text>
            <div class="flex flex-wrap gap-3">
                <flux:button wire:click="run" icon="arrow-path" variant="primary" data-test="run-nhs-research">
                    {{ __('Run research') }}
                </flux:button>
                <flux:button
                    type="button"
                    wire:click="refreshResearch"
                    wire:confirm="{{ __('Remove the saved research and check NHS sources again?') }}"
                    icon="arrow-path"
                    data-test="refresh-nhs-research"
                >
                    {{ __('Refresh research') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="danger"
                    wire:click="clearResearch"
                    wire:confirm="{{ __('Remove all saved NHS research?') }}"
                    icon="x-circle"
                    data-test="clear-nhs-research"
                >
                    {{ __('Remove all research') }}
                </flux:button>
            </div>
            <flux:text wire:loading>{{ __('Checking NHS sources and the Dene Healthcare catalogue. This can take a few minutes.') }}</flux:text>
        </flux:card>
    @endif
</div>
