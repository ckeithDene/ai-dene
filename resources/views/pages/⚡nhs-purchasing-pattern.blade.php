<?php

use App\Models\NhsProductOpportunity;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('NHS purchasing pattern')] class extends Component {
    #[Locked]
    public int $opportunityId;

    public function mount(NhsProductOpportunity $opportunity): void
    {
        $this->opportunityId = $opportunity->id;
    }

    #[Computed]
    public function opportunity(): NhsProductOpportunity
    {
        return NhsProductOpportunity::query()->with(['directives', 'gap'])->findOrFail($this->opportunityId);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    @php($opportunity = $this->opportunity)
    @php($gap = $opportunity->gap)

    <flux:link :href="route('nhs-purchasing-patterns')" wire:navigate>{{ __('NHS Purchasing Patterns') }}</flux:link>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl">{{ $opportunity->name }}</flux:heading>
                <flux:badge :color="$opportunity->demand_direction->isGrowth() ? 'green' : ($opportunity->demand_direction->isDecline() ? 'red' : 'zinc')">
                    {{ $opportunity->directionLabel() }}
                </flux:badge>
            </div>
            <flux:subheading>{{ $opportunity->category ?: __('Uncategorised') }}</flux:subheading>
        </div>
        <div class="text-right">
            <flux:text class="text-zinc-500">{{ $opportunity->priorityLabel() }}</flux:text>
            <flux:heading size="xl">{{ $opportunity->opportunity_score }}/100</flux:heading>
        </div>
    </div>

    <flux:callout
        :heading="$opportunity->requirement_confirmed ? __('Requirement for the listed organisations') : __('Predicted opportunity')"
        :text="$opportunity->requirement_confirmed
            ? __('A binding or contractual source applies to the organisations listed. This is still not a confirmed customer order.')
            : __('Predicted commercial opportunity. This is not confirmation that a GP practice must buy this product.')"
    />

    <flux:card class="flex flex-col gap-4">
        @if (filled($opportunity->explanation))
            <flux:text>{{ $opportunity->explanation }}</flux:text>
        @endif
        <dl class="grid gap-3 text-sm md:grid-cols-2">
            @foreach ([
                __('Demand') => $opportunity->demand_direction->label(),
                __('Timeframe') => $opportunity->timeframe?->label(),
                __('Confidence') => $opportunity->confidence,
                __('Commercial action') => $opportunity->commercial_action,
                __('Secondary demand') => $opportunity->secondary_demand,
                __('Sales proposition') => $opportunity->sales_proposition,
            ] as $label => $value)
                @if (filled($value))
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                        <dd>{{ $value }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>
    </flux:card>

    @if ($opportunity->directives->isNotEmpty())
        <div class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Directives') }}</flux:heading>
            @foreach ($opportunity->directives as $directive)
                <flux:card wire:key="pattern-directive-{{ $directive->id }}" class="flex flex-col gap-2">
                    <flux:heading>{{ $directive->title }}</flux:heading>
                    <flux:text>{{ $directive->obligation->label() }}{{ $directive->applies_to ? ' · '.$directive->applies_to : '' }}</flux:text>
                    @if (filled($directive->summary))
                        <flux:text>{{ $directive->summary }}</flux:text>
                    @endif
                    @if (filled($directive->commercial_implications))
                        <div>
                            <flux:heading>{{ __('How purchasing could change') }}</flux:heading>
                            <flux:text class="mt-1">{{ $directive->commercial_implications }}</flux:text>
                        </div>
                    @endif
                    <dl class="grid gap-3 text-sm md:grid-cols-3">
                        @foreach ([
                            __('Published') => $directive->published_on,
                            __('Updated') => $directive->updated_on,
                            __('Effective') => $directive->effective_on,
                        ] as $label => $value)
                            @if (filled($value))
                                <div>
                                    <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                                    <dd>{{ $value }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                    @if ($directive->source_url)
                        <flux:link :href="$directive->source_url" target="_blank" rel="noopener noreferrer">{{ $directive->source_title ?: $directive->issuer ?: __('Source') }}</flux:link>
                    @endif
                </flux:card>
            @endforeach
        </div>
    @endif

    @if ($gap)
        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Dene Healthcare catalogue') }}</flux:heading>
                <flux:text>{{ $gap->coverage->label() }} · {{ $gap->gap_type->label() }} · {{ $gap->sourcing_action->label() }}</flux:text>
            </div>
            @if ($gap->gap_score !== null)
                <flux:text>{{ __('Catalogue gap score: :score/100. Margin, stock and supplier availability were not read, so they are not included.', ['score' => $gap->gap_score]) }}</flux:text>
            @else
                <flux:text>{{ __('Catalogue gap score unverified.') }}</flux:text>
            @endif
            @if (filled($gap->missing_items))
                <flux:text>{{ $gap->missing_items }}</flux:text>
            @endif
            @if (filled($gap->recommendation))
                <flux:text>{{ $gap->recommendation }}</flux:text>
            @endif
            @if ($gap->matched_products)
                <div class="grid gap-2">
                    @foreach ($gap->matched_products as $product)
                        @if (is_array($product))
                            <flux:text>{{ $product['name'] ?? '' }}{{ filled($product['sku'] ?? null) ? ' · '.$product['sku'] : '' }}{{ filled($product['list_price'] ?? null) ? ' · '.$product['list_price'] : '' }}</flux:text>
                        @endif
                    @endforeach
                </div>
            @endif
        </flux:card>
    @endif
</div>
