<?php

use App\Models\OdooConnection;
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
    }

    #[Computed]
    public function hasApiKey(): bool
    {
        return filled(OdooConnection::current()?->api_key);
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

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="save-odoo-connection-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </x-pages::admin.layout>
</section>
