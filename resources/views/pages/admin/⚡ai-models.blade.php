<?php

use App\Models\AiModel;
use App\Services\OpenAiConnectionVerifier;
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
        $this->apiKey = $this->normalizedApiKey($this->apiKey);

        $validated = $this->validate([
            'model' => ['required', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'apiKey' => [
                Rule::requiredIf(fn (): bool => blank(AiModel::current()?->api_key)),
                'string',
                'max:2000',
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

        $this->recordVerification($aiModel);
    }

    /**
     * Store the form and check that ChatGPT connection.
     */
    public function testConnection(): void
    {
        $this->save();
    }

    /**
     * Remove paste artifacts from an API key.
     */
    protected function normalizedApiKey(string $apiKey): string
    {
        $apiKey = trim(str_replace(["\u{200B}", "\u{FEFF}"], '', $apiKey));

        if (preg_match('/^bearer\s+/i', $apiKey) === 1) {
            $apiKey = preg_replace('/^bearer\s+/i', '', $apiKey) ?? $apiKey;
        }

        return preg_replace('/\s+/u', '', $apiKey) ?? $apiKey;
    }

    #[Computed]
    public function hasApiKey(): bool
    {
        return filled(AiModel::current()?->api_key);
    }

    /**
     * @return array{verified: bool, error: string|null, checkedAt: string|null}|null
     */
    #[Computed]
    public function connectionStatus(): ?array
    {
        $aiModel = AiModel::current();

        if ($aiModel === null) {
            return null;
        }

        return [
            'verified' => $aiModel->verified_at !== null,
            'error' => $aiModel->verification_error,
            'checkedAt' => $aiModel->verified_at === null
                ? null
                : __('Last checked :time.', ['time' => $aiModel->verified_at->timezone(config('app.timezone'))->format('j M Y H:i')]),
        ];
    }

    /**
     * Store the result of a ChatGPT connection check.
     */
    protected function recordVerification(AiModel $aiModel): void
    {
        $result = (new OpenAiConnectionVerifier)->verify($aiModel);

        $aiModel->verified_at = $result->connected ? now() : null;
        $aiModel->verification_error = $result->connected ? null : $result->message;
        $aiModel->save();

        unset($this->connectionStatus);

        Flux::toast(
            variant: $result->connected ? 'success' : 'danger',
            text: $result->message,
        );
    }

    /**
     * Fill the model box from the list.
     */
    public function selectModel(string $model): void
    {
        $this->authorizeAccess();

        $allowed = array_column($this->modelChoices(), 'code');

        if (! in_array($model, $allowed, true)) {
            return;
        }

        $this->model = $model;
    }

    /**
     * Current ChatGPT model codes and what each one is for.
     *
     * @return list<array{code: string, use: string}>
     */
    public function modelChoices(): array
    {
        return [
            ['code' => 'gpt-6-astra', 'use' => __('Most capable. Slowest. Hard research.')],
            ['code' => 'gpt-6.1-sol', 'use' => __('Balanced everyday work.')],
            ['code' => 'gpt-6.1-terra', 'use' => __('Faster writing and visual tasks.')],
            ['code' => 'gpt-6-luna', 'use' => __('Fastest. Use this when a job times out.')],
        ];
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

    <x-pages::admin.layout wide :heading="__('AI Models')" :subheading="__('API connection details for ChatGPT')">
        <form wire:submit="save" class="my-6 w-full space-y-6" autocomplete="off">
            <div class="grid items-start gap-6 lg:grid-cols-2">
                <flux:input
                    wire:model="model"
                    :label="__('Model')"
                    type="text"
                    required
                    autofocus
                    autocomplete="off"
                    placeholder="gpt-6-luna"
                    :description="__('The ChatGPT model to use. Click a code to fill this box.')"
                />

                <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text class="font-medium">{{ __('Model codes') }}</flux:text>

                    <ul class="flex flex-col gap-3">
                        @foreach ($this->modelChoices() as $choice)
                            <li class="flex flex-col gap-1" wire:key="model-{{ $choice['code'] }}">
                                <button
                                    type="button"
                                    wire:click="selectModel('{{ $choice['code'] }}')"
                                    class="w-fit font-mono text-sm text-zinc-900 underline decoration-zinc-400 underline-offset-4 dark:text-zinc-100"
                                    data-test="model-choice-{{ $choice['code'] }}"
                                >
                                    {{ $choice['code'] }}
                                </button>
                                <flux:text>{{ $choice['use'] }}</flux:text>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <flux:input
                wire:model="organization"
                :label="__('Organization')"
                type="text"
                autocomplete="off"
                placeholder="org-abc123"
                :description="__('Leave this blank unless the key belongs to an OpenAI organization. A wrong value makes a valid key look rejected.')"
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

            @if ($this->connectionStatus['verified'] ?? false)
                <flux:callout variant="success" icon="check-circle" :heading="__('Connected')" :text="$this->connectionStatus['checkedAt']" />
            @elseif (filled($this->connectionStatus['error'] ?? null))
                <flux:callout variant="danger" icon="x-circle" :heading="__('Connection failed')" :text="$this->connectionStatus['error']" />
            @elseif ($this->connectionStatus !== null)
                <flux:callout variant="warning" icon="exclamation-circle" :heading="__('Not checked')" :text="__('This connection has not been verified.')" />
            @endif

            <div class="flex items-center justify-end gap-3">
                <flux:button type="button" wire:click="testConnection" data-test="test-ai-model-button">
                    {{ __('Test connection') }}
                </flux:button>

                <flux:button variant="primary" type="submit" data-test="save-ai-model-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </x-pages::admin.layout>
</section>
