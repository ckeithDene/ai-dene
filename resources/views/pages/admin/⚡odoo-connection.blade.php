<?php

use App\Models\OdooConnection;
use App\Services\OdooConnectionVerifier;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Odoo connection')] class extends Component {
    public string $url = '';

    public string $database = '';

    public string $username = '';

    public string $apiKey = '';

    /**
     * Load the saved connection without exposing the API key.
     */
    public function mount(): void
    {
        $this->authorizeAccess();

        $connection = OdooConnection::current();

        if ($connection === null) {
            return;
        }

        $this->url = $connection->url;
        $this->database = $connection->database;
        $this->username = $connection->username;
    }

    /**
     * Store the Odoo API connection details.
     */
    public function save(): void
    {
        $this->authorizeAccess();

        $this->url = rtrim(trim($this->url), '/');
        $this->database = trim($this->database);
        $this->username = trim($this->username);
        $this->apiKey = trim($this->apiKey);

        $validated = $this->validate([
            'url' => ['required', 'string', 'max:255', 'url:http,https'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'apiKey' => [
                Rule::requiredIf(fn (): bool => blank(OdooConnection::current()?->api_key)),
                'string',
                'max:255',
            ],
        ], [], [
            'url' => __('URL'),
            'database' => __('database'),
            'username' => __('username'),
            'apiKey' => __('API key'),
        ]);

        $connection = OdooConnection::current() ?? new OdooConnection;

        $connection->fill([
            'url' => $validated['url'],
            'database' => $validated['database'],
            'username' => $validated['username'],
        ]);

        if ($validated['apiKey'] !== '') {
            $connection->api_key = $validated['apiKey'];
        }

        $connection->save();

        $this->reset('apiKey');

        unset($this->hasApiKey);

        Flux::toast(variant: 'success', text: __('Odoo connection saved.'));

        $this->recordVerification($connection);
    }

    /**
     * Check the saved Odoo connection.
     */
    public function testConnection(): void
    {
        $this->authorizeAccess();

        $connection = OdooConnection::current();

        if ($connection === null) {
            Flux::toast(variant: 'danger', text: __('Save the connection before testing it.'));

            return;
        }

        $this->recordVerification($connection);
    }

    #[Computed]
    public function hasApiKey(): bool
    {
        return filled(OdooConnection::current()?->api_key);
    }

    /**
     * @return array{verified: bool, error: string|null, checkedAt: string|null}|null
     */
    #[Computed]
    public function connectionStatus(): ?array
    {
        $connection = OdooConnection::current();

        if ($connection === null) {
            return null;
        }

        return [
            'verified' => $connection->verified_at !== null,
            'error' => $connection->verification_error,
            'checkedAt' => $connection->verified_at === null
                ? null
                : __('Last checked :time.', ['time' => $connection->verified_at->timezone(config('app.timezone'))->format('j M Y H:i')]),
        ];
    }

    /**
     * Store the result of an Odoo authentication check.
     */
    protected function recordVerification(OdooConnection $connection): void
    {
        $result = (new OdooConnectionVerifier)->verify($connection);

        $connection->verified_at = $result->connected ? now() : null;
        $connection->verification_error = $result->connected ? null : $result->message;
        $connection->save();

        unset($this->connectionStatus);

        Flux::toast(
            variant: $result->connected ? 'success' : 'danger',
            text: $result->message,
        );
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

    <flux:heading level="2" class="sr-only">{{ __('Odoo connection') }}</flux:heading>

    <x-pages::admin.layout :heading="__('Odoo connection')" :subheading="__('API connection details for the Odoo instance')">
        <form wire:submit="save" class="my-6 w-full space-y-6" autocomplete="off">
            <flux:input
                wire:model="url"
                :label="__('URL')"
                type="url"
                required
                autofocus
                autocomplete="off"
                placeholder="https://mycompany.odoo.com"
                :description="__('The address of the Odoo instance.')"
            />

            <flux:input
                wire:model="database"
                :label="__('Database')"
                type="text"
                required
                autocomplete="off"
                placeholder="mycompany"
                :description="__('The Odoo database name.')"
            />

            <flux:input
                wire:model="username"
                :label="__('Username')"
                type="text"
                required
                autocomplete="off"
                placeholder="admin@mycompany.com"
                :description="__('The Odoo user this API key belongs to.')"
            />

            <flux:input
                wire:model="apiKey"
                :label="__('API key')"
                type="password"
                viewable
                autocomplete="new-password"
                :required="! $this->hasApiKey"
                :description="$this->hasApiKey
                    ? __('A key is already saved. Leave this blank to keep it. Create keys in Odoo under Preferences, then Account Security.')
                    : __('Create a key in Odoo under Preferences, then Account Security.')"
            />

            @if ($this->connectionStatus['verified'] ?? false)
                <flux:callout variant="success" icon="check-circle" :heading="__('Connected')" :text="$this->connectionStatus['checkedAt']" />
            @elseif (filled($this->connectionStatus['error'] ?? null))
                <flux:callout variant="danger" icon="x-circle" :heading="__('Connection failed')" :text="$this->connectionStatus['error']" />
            @elseif ($this->connectionStatus !== null)
                <flux:callout variant="warning" icon="exclamation-circle" :heading="__('Not checked')" :text="__('This connection has not been verified.')" />
            @endif

            <div class="flex items-center justify-end gap-3">
                <flux:button type="button" wire:click="testConnection" data-test="test-odoo-connection-button">
                    {{ __('Test connection') }}
                </flux:button>

                <flux:button variant="primary" type="submit" data-test="save-odoo-connection-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </x-pages::admin.layout>
</section>
