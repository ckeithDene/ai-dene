<?php

use App\Actions\ReviewBiggestFish;
use App\Enums\CustomerReviewStatus;
use App\Models\BiggestFish;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Biggest Fish')] class extends Component {
    use WithPagination;

    /**
     * Review the next batch of confirmed customers now.
     */
    public function checkNow(): void
    {
        Gate::authorize('accessAdmin');

        $result = app(ReviewBiggestFish::class)->handle();

        $this->resetPage();
        unset($this->fish);

        if ($result->skippedReason !== null) {
            Flux::toast(variant: 'danger', text: $result->skippedReason);

            return;
        }

        Flux::toast(variant: 'success', text: trans_choice(
            'Reviewed :count customer.|Reviewed :count customers.',
            $result->reviewed,
            ['count' => $result->reviewed],
        ));
    }

    /**
     * @return LengthAwarePaginator<int, BiggestFish>
     */
    #[Computed]
    public function fish(): LengthAwarePaginator
    {
        return BiggestFish::query()
            ->where('is_registered_company', true)
            ->where('estimated_value_gbp', '>', BiggestFish::VALUE_THRESHOLD_GBP)
            ->orderByDesc('estimated_value_gbp')
            ->orderByDesc('id')
            ->paginate(15);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Biggest Fish') }}</flux:heading>
            <flux:subheading>{{ __('Customers with a confirmed order and an estimated value over £20 million. Twenty customers are checked every hour.') }}</flux:subheading>
        </div>

        @if (auth()->user()->isAdmin())
            <flux:button wire:click="checkNow" icon="arrow-path" data-test="check-biggest-fish-button">
                {{ __('Check now') }}
            </flux:button>
        @endif
    </div>

    @if ($this->fish->isEmpty())
        <flux:text>{{ __('No customers over £20 million have been found yet.') }}</flux:text>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->fish as $fish)
                <flux:card wire:key="fish-{{ $fish->id }}" class="flex flex-col gap-4">
                    <div class="flex items-start gap-4">
                        @if ($fish->logoUrl())
                            <img
                                src="{{ $fish->logoUrl() }}"
                                alt="{{ $fish->company_name }}"
                                class="size-16 shrink-0 rounded-lg border border-zinc-200 bg-white object-contain dark:border-zinc-600"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'"
                            />
                            <div class="hidden size-16 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-lg font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-100">
                                {{ $fish->logoInitial() }}
                            </div>
                        @else
                            <div class="flex size-16 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-lg font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-100">
                                {{ $fish->logoInitial() }}
                            </div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <flux:heading class="truncate">
                                    <flux:link :href="route('companies.show', $fish->odoo_partner_id)" wire:navigate>
                                        {{ $fish->company_name }}
                                    </flux:link>
                                </flux:heading>

                                @if ($fish->status === CustomerReviewStatus::Failed)
                                    <flux:badge color="red">{{ __('Failed') }}</flux:badge>
                                @elseif ($fish->status === CustomerReviewStatus::Complete)
                                    <flux:badge color="green">{{ __('Reviewed') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc">{{ __('Pending') }}</flux:badge>
                                @endif
                            </div>

                            @if (filled($fish->address))
                                <flux:text class="mt-1">{{ $fish->address }}</flux:text>
                            @endif
                        </div>
                    </div>

                    <dl class="grid gap-3 text-sm">
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Total spent with us') }}</dt>
                            <dd>{{ $fish->totalSpentLabel() }}</dd>
                        </div>
                    </dl>

                    @if ($fish->status === CustomerReviewStatus::Failed)
                        <flux:callout variant="danger" icon="x-circle" :heading="__('Review failed')" :text="$fish->failure_reason" />
                    @else
                        @if (filled($fish->overview))
                            <flux:text>{{ $fish->overview }}</flux:text>
                        @endif

                        <dl class="grid gap-3 text-sm">
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Company size') }}</dt>
                                <dd>{{ $fish->company_size ?: __('Unknown') }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Estimated value') }}</dt>
                                <dd>{{ $fish->estimatedValueLabel() }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Market share') }}</dt>
                                <dd>{{ $fish->market_share ?: __('Unknown') }}</dd>
                            </div>
                        </dl>
                    @endif

                    <div class="flex flex-wrap gap-4">
                            <flux:link :href="route('companies.show', $fish->odoo_partner_id)" wire:navigate data-test="sales-strategy-link">
                                {{ __('Sales strategy') }}
                            </flux:link>
                            @if ($fish->websiteLabel())
                                <flux:link :href="$fish->websiteUrl()" target="_blank" rel="noopener noreferrer">
                                    {{ $fish->websiteLabel() }}
                                </flux:link>
                            @endif
                            @if ($fish->odooPartnerUrl())
                                <flux:link :href="$fish->odooPartnerUrl()" target="_blank" rel="noopener noreferrer">
                                    {{ __('View in Odoo') }}
                                </flux:link>
                            @endif
                        </div>
                </flux:card>
            @endforeach
        </div>

        <flux:pagination :paginator="$this->fish" />
    @endif
</div>
