<?php

use App\Models\AiModel;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('AI Models')] class extends Component {
    public string $model = '';

    public string $organization = '';

    public string $apiKey = '';

    /**
     * Load the saved model without exposing the API key.
     */
    public function mount(): void
    {
        $this->authorizeAccess();

        $aiModel = AiModel::current();

        if ($aiModel === null) {
            return;
        }

        $this->model = $aiModel->model;
        $this->organization = $aiModel->organization ?? '';
    }

    /**
     * Store the ChatGPT API connection details.
     */
    public function save(): void
    {
        $this->authorizeAccess();

        $this->model = trim($this->model);
        $this->organization = trim($this->organization);
        $this->apiKey = trim($this->apiKey);

        $validated = $this->validate([
            'model' => ['required', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'apiKey' => [
                Rule::requiredIf(fn (): bool => blank(AiModel::current()?->api_key)),
                'string',
                'max:255',
            ],
        ], [], [
            'model' => __('model'),
            'organization' => __('organization'),
            'apiKey' => __('API key'),
        ]);

        $aiModel = AiModel::current() ?? new AiModel;

        $aiModel->fill([
            'model' => $validated['model'],
            'organization' => $validated['organization'] !== '' ? $validated['organization'] : null,
        ]);

        if ($validated['apiKey'] !== '') {
            $aiModel->api_key = $validated['apiKey'];
        }

        $aiModel->save();

        $this->reset('apiKey');

        unset($this->hasApiKey);

        Flux::toast(variant: 'success', text: __('AI Models saved.'));
    }

    #[Computed]
    public function hasApiKey(): bool
    {
        return filled(AiModel::current()?->api_key);
    }

    /**
     * Limit this page to application admins.
     */
    protected function authorizeAccess(): void
    {
        Gate::authorize('accessAdmin');
    }
}; ?>

<section class="w-full">
    @include('partials.admin-heading')

    <flux:heading level="2" class="sr-only">{{ __('AI Models') }}</flux:heading>

    <x-pages::admin.layout :heading="__('AI Models')" :subheading="__('API connection details for ChatGPT')">
        <form wire:submit="save" class="my-6 w-full space-y-6" autocomplete="off">
            <flux:input
                wire:model="model"
                :label="__('Model')"
                type="text"
                required
                autofocus
                autocomplete="off"
                placeholder="gpt-4o"
                :description="__('The ChatGPT model to use.')"
            />

            <flux:input
                wire:model="organization"
                :label="__('Organization')"
                type="text"
                autocomplete="off"
                placeholder="org-abc123"
                :description="__('Optional. The OpenAI organization this key belongs to.')"
            />

            <flux:input
                wire:model="apiKey"
                :label="__('API key')"
                type="password"
                viewable
                autocomplete="new-password"
                :required="! $this->hasApiKey"
                :description="$this->hasApiKey
                    ? __('A key is already saved. Leave this blank to keep it. Create keys at platform.openai.com under API keys.')
                    : __('Create a key at platform.openai.com under API keys.')"
            />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="save-ai-model-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </x-pages::admin.layout>
</section>
