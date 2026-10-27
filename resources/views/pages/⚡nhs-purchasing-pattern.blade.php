<?php

use App\Actions\FindDeneProducts;
use App\Models\NhsProductOpportunity;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
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

    /**
     * Search the Dene Healthcare Odoo catalogue for products and services to push.
     */
    public function findProducts(FindDeneProducts $finder): void
    {
        Gate::authorize('accessAdmin');

        $result = $finder->forOpportunity($this->opportunity);
        unset($this->opportunity);

        Flux::toast(variant: $result->found ? 'success' : 'danger', text: $result->message);
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
                    <flux:heading>
                        <flux:link :href="route('nhs-purchasing-patterns.directives.show', $directive)" wire:navigate>{{ $directive->title }}</flux:link>
                    </flux:heading>
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

    <flux:card class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('What to push') }}</flux:heading>
        @if (filled($opportunity->pushText()))
            <flux:text>{{ $opportunity->pushText() }}</flux:text>
        @endif
        @if ($gap && filled($gap->recommendation) && $gap->recommendation !== $opportunity->pushText())
            <flux:text>{{ $gap->recommendation }}</flux:text>
        @endif
        @if (blank($opportunity->pushText()) && (! $gap || blank($gap->recommendation)))
            <flux:text>{{ __('No sales push has been recorded for this pattern yet.') }}</flux:text>
        @endif
    </flux:card>

    <flux:card class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Products that could benefit') }}</flux:heading>
            @if (auth()->user()->isAdmin())
                <flux:button wire:click="findProducts" icon="magnifying-glass" data-test="find-dene-products">{{ __('Find Dene products') }}</flux:button>
            @endif
        </div>
        <flux:text wire:loading wire:target="findProducts">{{ __('Searching the Dene Healthcare catalogue.') }}</flux:text>
        @if ($gap && filled($gap->catalogue_status))
            <flux:text>{{ $gap->catalogue_status }}</flux:text>
        @endif
        @if ($gap && $gap->goods() !== [])
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Product') }}</flux:table.column>
                    <flux:table.column>{{ __('SKU') }}</flux:table.column>
                    <flux:table.column>{{ __('Price') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($gap->goods() as $product)
                        <flux:table.row wire:key="pattern-product-{{ $product['id'] ?? $product['sku'] ?? $loop->index }}">
                            <flux:table.cell variant="strong">{{ $product['name'] ?? '' }}</flux:table.cell>
                            <flux:table.cell>{{ $product['sku'] ?? '' }}</flux:table.cell>
                            <flux:table.cell>{{ $product['list_price'] ?? '' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <flux:text>{{ __('No Dene Healthcare product matched this pattern.') }}</flux:text>
        @endif
        @if ($gap && filled($gap->missing_items))
            <flux:text>{{ $gap->missing_items }}</flux:text>
        @endif
        @if ($gap && $gap->matched_categories)
            <div>
                <flux:heading>{{ __('Product categories') }}</flux:heading>
                <flux:text class="mt-1">{{ implode(', ', $gap->matched_categories) }}</flux:text>
            </div>
        @endif
    </flux:card>

    <flux:card class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Services that could help') }}</flux:heading>
        @if ($gap && $gap->services() !== [])
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Service') }}</flux:table.column>
                    <flux:table.column>{{ __('SKU') }}</flux:table.column>
                    <flux:table.column>{{ __('Price') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($gap->services() as $service)
                        <flux:table.row wire:key="pattern-service-{{ $service['id'] ?? $service['sku'] ?? $loop->index }}">
                            <flux:table.cell variant="strong">{{ $service['name'] ?? '' }}</flux:table.cell>
                            <flux:table.cell>{{ $service['sku'] ?? '' }}</flux:table.cell>
                            <flux:table.cell>{{ $service['list_price'] ?? '' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <flux:text>{{ __('No Dene Healthcare service matched this pattern.') }}</flux:text>
        @endif
    </flux:card>
</div>
