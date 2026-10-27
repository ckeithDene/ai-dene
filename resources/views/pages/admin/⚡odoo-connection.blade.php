<?php

use App\Enums\OdooCompany;
use App\Models\OdooConnection;
use App\Services\OdooConnectionVerifier;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Odoo connections')] class extends Component {
    public string $url = '';

    public string $database = '';

    public string $username = '';

    public string $apiKey = '';

    public string $deneUrl = '';

    public string $deneDatabase = '';

    public string $deneUsername = '';

    public string $deneApiKey = '';

    /**
     * Load the saved connections without exposing the API keys.
     */
    public function mount(): void
    {
        $this->authorizeAccess();

        $this->fillConnection(OdooCompany::MidMeds);
        $this->fillConnection(OdooCompany::Dene);
    }

    /**
     * Store the MidMeds Odoo API connection details.
     */
    public function save(): void
    {
        $this->authorizeAccess();

        $this->persist(OdooCompany::MidMeds, 'url', 'database', 'username', 'apiKey');
    }

    /**
     * Store the Dene Healthcare Odoo API connection details.
     */
    public function saveDene(): void
    {
        $this->authorizeAccess();

        $this->persist(OdooCompany::Dene, 'deneUrl', 'deneDatabase', 'deneUsername', 'deneApiKey');
    }

    /**
     * Check the saved MidMeds Odoo connection.
     */
    public function testConnection(): void
    {
        $this->authorizeAccess();

        $this->test(OdooCompany::MidMeds);
    }

    /**
     * Check the saved Dene Healthcare Odoo connection.
     */
    public function testDeneConnection(): void
    {
        $this->authorizeAccess();

        $this->test(OdooCompany::Dene);
    }

    #[Computed]
    public function hasApiKey(): bool
    {
        return filled(OdooConnection::for(OdooCompany::MidMeds)?->api_key);
    }

    #[Computed]
    public function deneHasApiKey(): bool
    {
        return filled(OdooConnection::for(OdooCompany::Dene)?->api_key);
    }

    /**
     * @return array{verified: bool, error: string|null, checkedAt: string|null}|null
     */
    #[Computed]
    public function connectionStatus(): ?array
    {
        return $this->status(OdooCompany::MidMeds);
    }

    /**
     * @return array{verified: bool, error: string|null, checkedAt: string|null}|null
     */
    #[Computed]
    public function deneConnectionStatus(): ?array
    {
        return $this->status(OdooCompany::Dene);
    }

    /**
     * Limit this page to application admins.
     */
    protected function authorizeAccess(): void
    {
        Gate::authorize('accessAdmin');
    }

    private function fillConnection(OdooCompany $company): void
    {
        $connection = OdooConnection::for($company);

        if ($connection === null) {
            return;
        }

        if ($company === OdooCompany::MidMeds) {
            $this->url = $connection->url;
            $this->database = $connection->database;
            $this->username = $connection->username;

            return;
        }

        $this->deneUrl = $connection->url;
        $this->deneDatabase = $connection->database;
        $this->deneUsername = $connection->username;
    }

    private function persist(OdooCompany $company, string $urlField, string $databaseField, string $usernameField, string $apiKeyField): void
    {
        $this->{$urlField} = rtrim(trim($this->{$urlField}), '/');
        $this->{$databaseField} = trim($this->{$databaseField});
        $this->{$usernameField} = trim($this->{$usernameField});
        $this->{$apiKeyField} = trim($this->{$apiKeyField});

        $validated = $this->validate([
            $urlField => ['required', 'string', 'max:255', 'url:http,https'],
            $databaseField => ['required', 'string', 'max:255'],
            $usernameField => ['required', 'string', 'max:255'],
            $apiKeyField => [
                Rule::requiredIf(fn (): bool => blank(OdooConnection::for($company)?->api_key)),
                'string',
                'max:255',
            ],
        ], [], [
            $urlField => __('URL'),
            $databaseField => __('database'),
            $usernameField => __('username'),
            $apiKeyField => __('API key'),
        ]);

        $connection = OdooConnection::for($company) ?? new OdooConnection;

        $connection->fill([
            'company' => $company,
            'url' => $validated[$urlField],
            'database' => $validated[$databaseField],
            'username' => $validated[$usernameField],
        ]);

        if ($validated[$apiKeyField] !== '') {
            $connection->api_key = $validated[$apiKeyField];
        }

        $connection->save();

        $this->reset($apiKeyField);

        unset($this->hasApiKey, $this->deneHasApiKey);

        Flux::toast(variant: 'success', text: __(':company connection saved.', ['company' => $company->label()]));

        $this->recordVerification($connection);
    }

    private function test(OdooCompany $company): void
    {
        $connection = OdooConnection::for($company);

        if ($connection === null) {
            Flux::toast(variant: 'danger', text: __('Save the connection before testing it.'));

            return;
        }

        $this->recordVerification($connection);
    }

    /**
     * @return array{verified: bool, error: string|null, checkedAt: string|null}|null
     */
    private function status(OdooCompany $company): ?array
    {
        $connection = OdooConnection::for($company);

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
    private function recordVerification(OdooConnection $connection): void
    {
        $result = (new OdooConnectionVerifier)->verify($connection);

        $connection->verified_at = $result->connected ? now() : null;
        $connection->verification_error = $result->connected ? null : $result->message;
        $connection->save();

        unset($this->connectionStatus, $this->deneConnectionStatus);

        Flux::toast(
            variant: $result->connected ? 'success' : 'danger',
            text: $result->message,
        );
    }
}; ?>

<section class="w-full">
    @include('partials.admin-heading')

    <flux:heading level="2" class="sr-only">{{ __('Odoo connections') }}</flux:heading>

    <x-pages::admin.layout :heading="__('Odoo connections')" :subheading="__('MidMeds is used by First Order Diamonds and Biggest Fish. Dene Healthcare is used by NHS Purchasing Patterns.')">
        <form wire:submit="save" class="my-6 w-full space-y-6" autocomplete="off">
            <flux:heading>{{ __('MidMeds') }}</flux:heading>

            <flux:input
                wire:model="url"
                :label="__('URL')"
                type="url"
                required
                autofocus
                autocomplete="off"
                placeholder="https://midmeds.odoo.com"
                :description="__('The address of the MidMeds Odoo instance.')"
            />

            <flux:input
                wire:model="database"
                :label="__('Database')"
                type="text"
                required
                autocomplete="off"
                placeholder="midmeds"
                :description="__('The Odoo database name.')"
            />

            <flux:input
                wire:model="username"
                :label="__('Username')"
                type="text"
                required
                autocomplete="off"
                placeholder="admin@midmeds.com"
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

        <flux:separator />

        <form wire:submit="saveDene" class="my-6 w-full space-y-6" autocomplete="off">
            <flux:heading>{{ __('Dene Healthcare') }}</flux:heading>

            <flux:input
                wire:model="deneUrl"
                :label="__('URL')"
                type="url"
                required
                autocomplete="off"
                placeholder="https://dene.odoo.com"
                :description="__('The address of the Dene Healthcare Odoo instance.')"
            />

            <flux:input
                wire:model="deneDatabase"
                :label="__('Database')"
                type="text"
                required
                autocomplete="off"
                placeholder="dene"
                :description="__('The Odoo database name.')"
            />

            <flux:input
                wire:model="deneUsername"
                :label="__('Username')"
                type="text"
                required
                autocomplete="off"
                placeholder="admin@denehealthcare.com"
                :description="__('The Odoo user this API key belongs to.')"
            />

            <flux:input
                wire:model="deneApiKey"
                :label="__('API key')"
                type="password"
                viewable
                autocomplete="new-password"
                :required="! $this->deneHasApiKey"
                :description="$this->deneHasApiKey
                    ? __('A key is already saved. Leave this blank to keep it. Create keys in Odoo under Preferences, then Account Security.')
                    : __('Create a key in Odoo under Preferences, then Account Security.')"
            />

            @if ($this->deneConnectionStatus['verified'] ?? false)
                <flux:callout variant="success" icon="check-circle" :heading="__('Connected')" :text="$this->deneConnectionStatus['checkedAt']" />
            @elseif (filled($this->deneConnectionStatus['error'] ?? null))
                <flux:callout variant="danger" icon="x-circle" :heading="__('Connection failed')" :text="$this->deneConnectionStatus['error']" />
            @elseif ($this->deneConnectionStatus !== null)
                <flux:callout variant="warning" icon="exclamation-circle" :heading="__('Not checked')" :text="__('This connection has not been verified.')" />
            @endif

            <div class="flex items-center justify-end gap-3">
                <flux:button type="button" wire:click="testDeneConnection" data-test="test-dene-odoo-connection-button">
                    {{ __('Test connection') }}
                </flux:button>

                <flux:button variant="primary" type="submit" data-test="save-dene-odoo-connection-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </x-pages::admin.layout>
</section>
