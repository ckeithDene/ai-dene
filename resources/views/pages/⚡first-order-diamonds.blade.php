<?php

use App\Actions\ReviewFirstOrderDiamonds;
use App\Enums\FirstOrderDiamondStatus;
use App\Models\FirstOrderDiamond;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('First Order Diamonds')] class extends Component {
    use WithPagination;

    /**
     * Review new confirmed first orders now.
     */
    public function checkNow(): void
    {
        Gate::authorize('accessAdmin');

        $result = app(ReviewFirstOrderDiamonds::class)->handle();

        $this->resetPage();
        unset($this->diamonds);

        if ($result->skippedReason !== null) {
            Flux::toast(variant: 'danger', text: $result->skippedReason);

            return;
        }

        Flux::toast(variant: 'success', text: trans_choice(
            'Reviewed :count first order.|Reviewed :count first orders.',
            $result->reviewed,
            ['count' => $result->reviewed],
        ));
    }

    /**
     * @return LengthAwarePaginator<int, FirstOrderDiamond>
     */
    #[Computed]
    public function diamonds(): LengthAwarePaginator
    {
        return FirstOrderDiamond::query()
            ->where('is_registered_company', true)
            ->latest('ordered_at')
            ->latest('id')
            ->paginate(15);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('First Order Diamonds') }}</flux:heading>
            <flux:subheading>{{ __('How strong confirmed first orders from 1 October 2026 look as leads. Each company is reviewed once. New orders are checked every hour, up to 20 companies at a time.') }}</flux:subheading>
        </div>

        @if (auth()->user()->isAdmin())
            <flux:button wire:click="checkNow" icon="arrow-path" data-test="check-first-orders-button">
                {{ __('Check now') }}
            </flux:button>
        @endif
    </div>

    @if ($this->diamonds->isEmpty())
        <flux:text>{{ __('No registered-company first orders to show yet.') }}</flux:text>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->diamonds as $diamond)
                <flux:card wire:key="diamond-{{ $diamond->id }}" class="flex flex-col gap-4">
                    <div class="flex items-start gap-4">
                        @if ($diamond->logoUrl())
                            <img
                                src="{{ $diamond->logoUrl() }}"
                                alt="{{ $diamond->company_name }}"
                                class="size-16 shrink-0 rounded-lg border border-zinc-200 bg-white object-contain dark:border-zinc-600"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'"
                            />
                            <div class="hidden size-16 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-lg font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-100">
                                {{ $diamond->logoInitial() }}
                            </div>
                        @else
                            <div class="flex size-16 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-lg font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-100">
                                {{ $diamond->logoInitial() }}
                            </div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <flux:heading class="truncate">{{ $diamond->company_name }}</flux:heading>

                                @if ($diamond->status === FirstOrderDiamondStatus::Failed)
                                    <flux:badge color="red">{{ __('Failed') }}</flux:badge>
                                @elseif ($diamond->status === FirstOrderDiamondStatus::Complete)
                                    <flux:badge color="green">{{ __('Reviewed') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc">{{ __('Pending') }}</flux:badge>
                                @endif
                            </div>

                            @if (filled($diamond->address))
                                <flux:text class="mt-1">{{ $diamond->address }}</flux:text>
                            @endif

                            <flux:text class="mt-1">{{ $diamond->order_name }}</flux:text>
                        </div>
                    </div>

                    <dl class="grid gap-3 text-sm">
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Order date') }}</dt>
                            <dd>{{ $diamond->ordered_at?->timezone(config('app.timezone'))->format('j M Y') ?: __('Unknown') }}</dd>
                        </div>
                        @if (filled($diamond->order_lines))
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Ordered') }}</dt>
                                <dd>
                                    <ul>
                                        @foreach ($diamond->order_lines as $line)
                                            @if (is_array($line) && is_string($line['name'] ?? null) && $line['name'] !== '')
                                                <li>{{ is_string($line['quantity'] ?? null) ? $line['quantity'] : '1' }} × {{ $line['name'] }}</li>
                                            @endif
                                        @endforeach
                                    </ul>
                                </dd>
                            </div>
                        @endif
                    </dl>

                    @if ($diamond->status === FirstOrderDiamondStatus::Failed)
                        <flux:callout variant="danger" icon="x-circle" :heading="__('Review failed')" :text="$diamond->failure_reason" />
                    @else
                        @if (filled($diamond->overview))
                            <flux:text>{{ $diamond->overview }}</flux:text>
                        @endif

                        <dl class="grid gap-3 text-sm">
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Company size') }}</dt>
                                <dd>{{ $diamond->company_size ?: __('Unknown') }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Estimated value') }}</dt>
                                <dd>{{ $diamond->estimated_value ?: __('Unknown') }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Market share') }}</dt>
                                <dd>{{ $diamond->market_share ?: __('Unknown') }}</dd>
                            </div>
                        </dl>
                    @endif

                    @if ($diamond->websiteUrl() || $diamond->odooOrderUrl())
                        <div class="flex flex-wrap gap-4">
                            @if ($diamond->websiteLabel())
                                <flux:link :href="$diamond->websiteUrl()" target="_blank" rel="noopener noreferrer">
                                    {{ $diamond->websiteLabel() }}
                                </flux:link>
                            @endif
                            @if ($diamond->odooOrderUrl())
                                <flux:link :href="$diamond->odooOrderUrl()" target="_blank" rel="noopener noreferrer">
                                    {{ __('View in Odoo') }}
                                </flux:link>
                            @endif
                        </div>
                    @endif
                </flux:card>
            @endforeach
        </div>

        <flux:pagination :paginator="$this->diamonds" />
    @endif
</div>
