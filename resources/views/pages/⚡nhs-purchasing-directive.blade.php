<?php

use App\Models\NhsDirective;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('NHS directive')] class extends Component {
    #[Locked]
    public int $directiveId;

    public function mount(NhsDirective $directive): void
    {
        $this->directiveId = $directive->id;
    }

    #[Computed]
    public function directive(): NhsDirective
    {
        return NhsDirective::query()
            ->with(['opportunities.gap'])
            ->findOrFail($this->directiveId);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    @php($directive = $this->directive)

    <flux:link :href="route('nhs-purchasing-patterns')" wire:navigate>{{ __('NHS Purchasing Patterns') }}</flux:link>

    <div>
        <flux:heading size="xl">{{ $directive->title }}</flux:heading>
        <flux:subheading>{{ collect([$directive->issuer, $directive->published_on, $directive->obligation->label(), $directive->applies_to])->filter()->implode(' · ') }}</flux:subheading>
    </div>

    <flux:callout
        :heading="$directive->requirement_confirmed ? __('Requirement for the listed organisations') : __('Predicted effect')"
        :text="$directive->requirement_confirmed
            ? __('A binding or contractual source applies to the organisations listed. This is still not a confirmed customer order.')
            : __('This is a predicted effect on GP purchasing. It is not confirmation that a practice must buy or change a product.')"
    />

    <flux:card class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Potential impact on GP purchasing') }}</flux:heading>
        @if (filled($directive->commercial_implications))
            <flux:text>{{ $directive->commercial_implications }}</flux:text>
        @endif
        @if (filled($directive->summary))
            <flux:text>{{ $directive->summary }}</flux:text>
        @endif
        @if (blank($directive->commercial_implications) && blank($directive->summary))
            <flux:text>{{ __('No purchasing impact has been recorded for this directive yet.') }}</flux:text>
        @endif
        @if ($directive->source_url)
            <flux:link :href="$directive->source_url" target="_blank" rel="noopener noreferrer">{{ $directive->source_title ?: $directive->issuer ?: __('Source') }}</flux:link>
        @endif
    </flux:card>

    @if ($directive->opportunities->isEmpty())
        <flux:text>{{ __('No purchasing patterns are linked to this directive yet.') }}</flux:text>
    @else
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('What Dene Healthcare could offer') }}</flux:heading>
            @foreach ($directive->opportunities as $opportunity)
                <flux:card wire:key="directive-opportunity-{{ $opportunity->id }}" class="flex flex-col gap-4">
                    <div>
                        <flux:heading>
                            <flux:link :href="route('nhs-purchasing-patterns.show', $opportunity)" wire:navigate>{{ $opportunity->name }}</flux:link>
                        </flux:heading>
                        <flux:text>{{ $opportunity->demand_direction->outlookLabel() }}{{ filled($opportunity->rationale) ? ' · '.$opportunity->rationale : '' }}</flux:text>
                    </div>
                    @if (filled($opportunity->pushText()))
                        <div>
                            <flux:heading>{{ __('What to push') }}</flux:heading>
                            <flux:text class="mt-1">{{ $opportunity->pushText() }}</flux:text>
                        </div>
                    @endif
                    <div>
                        <flux:heading>{{ __('Products that could benefit') }}</flux:heading>
                        @if ($opportunity->gap && $opportunity->gap->goods() !== [])
                            <div class="mt-2 grid gap-1">
                                @foreach ($opportunity->gap->goods() as $product)
                                    <flux:text>{{ $product['name'] ?? '' }}{{ filled($product['sku'] ?? null) ? ' · '.$product['sku'] : '' }}{{ filled($product['list_price'] ?? null) ? ' · '.$product['list_price'] : '' }}</flux:text>
                                @endforeach
                            </div>
                        @else
                            <flux:text class="mt-1">{{ __('No Dene Healthcare product matched this pattern.') }}</flux:text>
                        @endif
                    </div>
                    <div>
                        <flux:heading>{{ __('Services that could help') }}</flux:heading>
                        @if ($opportunity->gap && $opportunity->gap->services() !== [])
                            <div class="mt-2 grid gap-1">
                                @foreach ($opportunity->gap->services() as $service)
                                    <flux:text>{{ $service['name'] ?? '' }}{{ filled($service['sku'] ?? null) ? ' · '.$service['sku'] : '' }}{{ filled($service['list_price'] ?? null) ? ' · '.$service['list_price'] : '' }}</flux:text>
                                @endforeach
                            </div>
                        @else
                            <flux:text class="mt-1">{{ __('No Dene Healthcare service matched this pattern.') }}</flux:text>
                        @endif
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
