<?php

use App\Actions\ResearchGpPremises;
use App\Models\GpPremisesLead;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('GP premises project')] class extends Component {
    #[Locked]
    public int $leadId;

    public function mount(GpPremisesLead $lead): void
    {
        $this->leadId = $lead->id;
    }

    /**
     * Look for a newer public status for this project.
     */
    public function check(ResearchGpPremises $research): void
    {
        Gate::authorize('accessAdmin');

        $result = $research->refresh($this->lead);
        unset($this->lead);

        if ($result->skippedReason !== null) {
            Flux::toast(variant: 'danger', text: $result->skippedReason);

            return;
        }

        Flux::toast(variant: 'success', text: __('Project status checked.'));
    }

    #[Computed]
    public function lead(): GpPremisesLead
    {
        return GpPremisesLead::query()->findOrFail($this->leadId);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    @php($lead = $this->lead)

    <flux:link :href="route('primary-care-spaces')" wire:navigate>{{ __('Primary Care Spaces') }}</flux:link>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl">{{ $lead->surgery_name }}</flux:heading>
                <flux:badge color="zinc">{{ $lead->project_stage->groupLabel() }}</flux:badge>
            </div>
            <flux:subheading>{{ $lead->locationLabel() }}</flux:subheading>
        </div>
        <div class="text-right">
            <flux:text class="text-zinc-500">{{ __('Opportunity') }}</flux:text>
            <flux:heading size="xl">{{ $lead->opportunity_score }}/100</flux:heading>
        </div>
    </div>

    <flux:card class="flex flex-col gap-4">
        <flux:heading>{{ $lead->development_type->label() }}</flux:heading>
        @if (filled($lead->development_details))
            <flux:text>{{ $lead->development_details }}</flux:text>
        @endif
        @if (filled($lead->summary))
            <flux:text>{{ $lead->summary }}</flux:text>
        @endif
        <dl class="grid gap-3 text-sm md:grid-cols-2">
            @foreach ([
                __('Status') => $lead->project_stage->label(),
                __('Sales timing') => $lead->sales_timing,
                __('Source published') => $lead->source_published_on,
                __('Project dates') => $lead->project_dates,
                __('Funding') => $lead->funding,
                __('Planning reference') => $lead->planning_reference,
                __('Planning authority') => $lead->planning_authority,
                __('Source confidence') => $lead->source_confidence,
                __('Priority') => $lead->sales_priority->label(),
            ] as $label => $value)
                @if (filled($value))
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                        <dd>{{ $value }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>
        @if (filled($lead->fit_out_opportunity))
            <div>
                <flux:heading>{{ __('Possible fit-out') }}</flux:heading>
                <flux:text class="mt-1">{{ $lead->fit_out_opportunity }}</flux:text>
            </div>
        @endif
        @if (filled($lead->next_action))
            <div>
                <flux:heading>{{ __('Next sales action') }}</flux:heading>
                <flux:text class="mt-1">{{ $lead->next_action }}</flux:text>
            </div>
        @endif
        <div class="flex flex-wrap gap-4">
            @if ($lead->website)
                <flux:link :href="$lead->website" target="_blank" rel="noopener noreferrer">{{ __('Surgery website') }}</flux:link>
            @else
                <flux:text>{{ __('Surgery website not found') }}</flux:text>
            @endif
            @foreach ($lead->sources ?? [] as $source)
                @if (is_array($source) && filled($source['url'] ?? null))
                    <flux:link :href="$source['url']" target="_blank" rel="noopener noreferrer">{{ $source['title'] ?? __('Source') }}</flux:link>
                @endif
            @endforeach
        </div>
        @if ($lead->last_verified_at)
            <flux:text class="text-zinc-500">{{ __('Last checked :date', ['date' => $lead->last_verified_at->timezone(config('app.timezone'))->format('j M Y H:i')]) }}</flux:text>
        @endif
    </flux:card>

    @if (auth()->user()->isAdmin())
        <flux:button wire:click="check" icon="arrow-path" data-test="check-gp-project">
            {{ __('Check this project') }}
        </flux:button>
    @endif
</div>
