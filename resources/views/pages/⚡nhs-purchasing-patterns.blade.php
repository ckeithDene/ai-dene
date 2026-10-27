<?php

use App\Actions\ResearchNhsPurchasing;
use App\Enums\NhsDemandDirection;
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
        unset($this->opportunities, $this->directives, $this->counts, $this->settings);

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
                            <div class="text-right">
                                <flux:text class="text-zinc-500">{{ $opportunity->priorityLabel() }}</flux:text>
                                <flux:heading>{{ $opportunity->opportunity_score }}/100</flux:heading>
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
                <flux:card wire:key="nhs-directive-{{ $directive->id }}">
                    <flux:heading>{{ $directive->title }}</flux:heading>
                    <flux:text>{{ collect([$directive->issuer, $directive->published_on, $directive->obligation->label()])->filter()->implode(' · ') }}</flux:text>
                    @if ($directive->source_url)
                        <flux:link :href="$directive->source_url" target="_blank" rel="noopener noreferrer">{{ $directive->source_title ?: __('Source') }}</flux:link>
                    @endif
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
            <flux:button wire:click="run" icon="arrow-path" variant="primary" data-test="run-nhs-research">
                {{ __('Run research') }}
            </flux:button>
            <flux:text wire:loading>{{ __('Checking NHS sources and the Dene Healthcare catalogue. This can take a few minutes.') }}</flux:text>
        </flux:card>
    @endif
</div>
