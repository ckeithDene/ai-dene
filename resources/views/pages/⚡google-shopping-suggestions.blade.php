<?php

use App\Actions\CheckGoogleShoppingPrices;
use App\Enums\CustomerReviewStatus;
use App\Models\GoogleShoppingSuggestion;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Google Shopping Suggestions')] class extends Component {
    use WithPagination;

    #[Url]
    public string $view = 'sales';

    public function updatedView(string $value): void
    {
        if (! in_array($value, ['sales', 'cost', 'all'], true)) {
            $this->view = 'sales';
        }

        $this->resetPage();
    }

    /**
     * Price the next batch of best sellers now.
     */
    public function checkNow(): void
    {
        Gate::authorize('accessAdmin');

        $result = app(CheckGoogleShoppingPrices::class)->handle();

        $this->resetPage();
        unset($this->suggestions, $this->counts);

        if ($result->skippedReason !== null) {
            Flux::toast(variant: 'danger', text: $result->skippedReason);

            return;
        }

        Flux::toast(variant: 'success', text: trans_choice(
            'Checked :count product.|Checked :count products.',
            $result->reviewed,
            ['count' => $result->reviewed],
        ));
    }

    /**
     * @return LengthAwarePaginator<int, GoogleShoppingSuggestion>
     */
    #[Computed]
    public function suggestions(): LengthAwarePaginator
    {
        $query = GoogleShoppingSuggestion::query();

        match ($this->view) {
            'cost' => $query->where('beats_cost_price', true)
                ->orderByDesc('potential_margin_percent'),
            'all' => $query,
            default => $query->where('beats_sales_price', true),
        };

        return $query->orderByDesc('units_sold')
            ->orderBy('id')
            ->paginate(25);
    }

    /**
     * @return array{sales: int, cost: int, all: int}
     */
    #[Computed]
    public function counts(): array
    {
        return [
            'sales' => GoogleShoppingSuggestion::query()->where('beats_sales_price', true)->count(),
            'cost' => GoogleShoppingSuggestion::query()->where('beats_cost_price', true)->count(),
            'all' => GoogleShoppingSuggestion::query()->count(),
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Google Shopping Suggestions') }}</flux:heading>
            <flux:subheading>{{ __('Products where we could be the cheapest seller on Google Shopping. Twenty products are checked every hour, working down from our best sellers by units sold. Our prices have 20% VAT added before comparing.') }}</flux:subheading>
        </div>

        @if (auth()->user()->isAdmin())
            <flux:button wire:click="checkNow" icon="arrow-path" data-test="check-google-shopping-button">
                {{ __('Check now') }}
            </flux:button>
        @endif
    </div>

    <flux:radio.group wire:model.live="view" variant="segmented" class="max-w-2xl">
        <flux:radio value="sales" :label="__('Sales price is cheapest').' ('.$this->counts['sales'].')'" />
        <flux:radio value="cost" :label="__('Cost price is cheapest').' ('.$this->counts['cost'].')'" />
        <flux:radio value="all" :label="__('All checked').' ('.$this->counts['all'].')'" />
    </flux:radio.group>

    @if ($view === 'sales')
        <flux:text>{{ __('Our current sales price, including VAT, is already below the cheapest Google Shopping price.') }}</flux:text>
    @elseif ($view === 'cost')
        <flux:text>{{ __('Our cost price is below the cheapest Google Shopping price. The margin assumes we list 1p below the cheapest seller, and is worked out excluding VAT.') }}</flux:text>
    @endif

    @if ($this->suggestions->isEmpty())
        <flux:text>{{ __('No products have been found here yet.') }}</flux:text>
    @else
        <flux:table :paginate="$this->suggestions">
            <flux:table.columns>
                <flux:table.column>{{ __('Product') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Units sold') }}</flux:table.column>
                @if ($view === 'cost')
                    <flux:table.column align="end">{{ __('Our cost') }}</flux:table.column>
                @endif
                <flux:table.column align="end">{{ __('Our price inc VAT') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Cheapest on Google') }}</flux:table.column>
                @if ($view === 'sales')
                    <flux:table.column align="end">{{ __('We are cheaper by') }}</flux:table.column>
                @elseif ($view === 'cost')
                    <flux:table.column align="end">{{ __('Price to beat inc VAT') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Potential margin') }}</flux:table.column>
                @else
                    <flux:table.column>{{ __('Result') }}</flux:table.column>
                @endif
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->suggestions as $suggestion)
                    <flux:table.row wire:key="shopping-{{ $suggestion->id }}">
                        <flux:table.cell class="max-w-md whitespace-normal">
                            <div class="font-medium text-zinc-800 dark:text-white">
                                @if ($suggestion->odooProductUrl())
                                    <flux:link :href="$suggestion->odooProductUrl()" target="_blank" rel="noopener noreferrer">{{ $suggestion->product_name }}</flux:link>
                                @else
                                    {{ $suggestion->product_name }}
                                @endif
                            </div>
                            @if (filled($suggestion->sku))
                                <flux:text size="sm">{{ $suggestion->sku }}</flux:text>
                            @endif
                            @if (filled($suggestion->cheapest_seller))
                                <flux:text size="sm">
                                    {{ __('Cheapest:') }}
                                    @if ($suggestion->cheapestUrl())
                                        <flux:link :href="$suggestion->cheapestUrl()" target="_blank" rel="noopener noreferrer">{{ $suggestion->cheapest_seller }}</flux:link>
                                    @else
                                        {{ $suggestion->cheapest_seller }}
                                    @endif
                                    @if (filled($suggestion->matched_product))
                                        &middot; {{ $suggestion->matched_product }}
                                    @endif
                                </flux:text>
                            @endif
                            @if (filled($suggestion->notes))
                                <flux:text size="sm" class="italic">{{ $suggestion->notes }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ $suggestion->unitsSoldLabel() }}</flux:table.cell>
                        @if ($view === 'cost')
                            <flux:table.cell align="end">{{ GoogleShoppingSuggestion::money($suggestion->cost_price) }}</flux:table.cell>
                        @endif
                        <flux:table.cell align="end">{{ GoogleShoppingSuggestion::money($suggestion->salesPriceIncVat()) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ GoogleShoppingSuggestion::money($suggestion->cheapest_price) }}</flux:table.cell>
                        @if ($view === 'sales')
                            <flux:table.cell align="end">
                                <flux:badge color="green" size="sm">{{ GoogleShoppingSuggestion::money($suggestion->salesSaving()) }}</flux:badge>
                            </flux:table.cell>
                        @elseif ($view === 'cost')
                            <flux:table.cell align="end">{{ GoogleShoppingSuggestion::money($suggestion->targetPriceIncVat()) }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <div>{{ GoogleShoppingSuggestion::money($suggestion->potential_margin) }}</div>
                                <flux:text size="sm">{{ number_format((float) $suggestion->potential_margin_percent, 1) }}%</flux:text>
                            </flux:table.cell>
                        @else
                            <flux:table.cell>
                                @if ($suggestion->status === CustomerReviewStatus::Failed)
                                    <flux:badge color="red" size="sm">{{ __('Failed') }}</flux:badge>
                                @elseif ($suggestion->status === CustomerReviewStatus::Pending)
                                    <flux:badge color="zinc" size="sm">{{ __('Pending') }}</flux:badge>
                                @elseif ($suggestion->cheapest_price === null)
                                    <flux:badge color="zinc" size="sm">{{ __('No match found') }}</flux:badge>
                                @elseif ($suggestion->beats_sales_price)
                                    <flux:badge color="green" size="sm">{{ __('Sales price is cheapest') }}</flux:badge>
                                @elseif ($suggestion->beats_cost_price)
                                    <flux:badge color="amber" size="sm">{{ __('Cost price is cheapest') }}</flux:badge>
                                @else
                                    <flux:badge color="red" size="sm">{{ __('Cannot beat') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                        @endif
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
