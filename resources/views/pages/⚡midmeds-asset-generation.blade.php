<?php

use App\Actions\CreateComboImage;
use App\Models\OdooConnection;
use App\Services\MidMedsProductSearch;
use App\Services\OdooClient;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('MidMeds Asset Generation')] class extends Component {
    public string $query = '';

    public bool $searched = false;

    public ?string $notice = null;

    public ?string $image = null;

    /**
     * @var array<int, array{id: int, name: string, sku: string, image: string}>
     */
    public array $results = [];

    /**
     * @var array<int, array{id: int, name: string, sku: string, image: string}>
     */
    public array $selected = [];

    public function productLimit(): int
    {
        return CreateComboImage::MAX_PRODUCTS;
    }

    /**
     * Search the MidMeds catalogue.
     */
    public function search(MidMedsProductSearch $search): void
    {
        $this->notice = null;
        $this->searched = false;
        $this->query = trim($this->query);

        $this->validate([
            'query' => ['required', 'string', 'min:2', 'max:80'],
        ], [
            'query.required' => 'Enter a product name or SKU.',
            'query.min' => 'Enter at least 2 characters.',
        ]);

        $connection = OdooConnection::current();

        if (! $connection instanceof OdooConnection) {
            $this->results = [];
            $this->notice = 'Connect the MidMeds Odoo account before searching the catalogue.';
            Flux::toast(variant: 'danger', text: $this->notice);

            return;
        }

        try {
            $this->results = $search->search(new OdooClient($connection), $this->query);
            $this->searched = true;
        } catch (\RuntimeException $exception) {
            $this->results = [];
            $this->notice = $exception->getMessage();
            Flux::toast(variant: 'danger', text: $this->notice);
        }
    }

    /**
     * Add a search result to the offer.
     */
    public function addProduct(int $id): void
    {
        $this->notice = null;

        if ($this->hasProduct($id)) {
            return;
        }

        if (count($this->selected) >= CreateComboImage::MAX_PRODUCTS) {
            $this->notice = 'A combo image can include up to '.CreateComboImage::MAX_PRODUCTS.' products.';
            Flux::toast(variant: 'danger', text: $this->notice);

            return;
        }

        foreach ($this->results as $product) {
            if ($product['id'] === $id) {
                $this->selected[] = $product;

                return;
            }
        }
    }

    /**
     * Remove a product from the offer.
     */
    public function removeProduct(int $id): void
    {
        $this->notice = null;
        $this->selected = array_values(array_filter(
            $this->selected,
            fn (array $product): bool => $product['id'] !== $id,
        ));
    }

    public function hasProduct(int $id): bool
    {
        foreach ($this->selected as $product) {
            if ($product['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ask ChatGPT to group the selected product photos.
     */
    public function create(CreateComboImage $combo): void
    {
        $this->notice = null;

        $ids = [];

        foreach ($this->selected as $product) {
            $id = $product['id'] ?? null;

            if (is_int($id) && $id > 0) {
                $ids[] = $id;
            }
        }

        try {
            $this->image = $combo->handle(array_values(array_unique($ids)), $this->image);
        } catch (\RuntimeException $exception) {
            $this->notice = $exception->getMessage();
            Flux::toast(variant: 'danger', text: $this->notice);

            return;
        }

        Flux::toast(variant: 'success', text: 'Combo image ready.');
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('MidMeds Asset Generation') }}</flux:heading>
        <flux:subheading>{{ __('Generate product assets for the MidMeds shop.') }}</flux:subheading>
    </div>

    <div class="flex max-w-3xl flex-col gap-2">
        <flux:heading size="lg">{{ __('Combo image') }}</flux:heading>
        <flux:text>{{ __('Search the MidMeds catalogue and pick the products in an offer. ChatGPT groups their photos into one image on a black background, ready for the website or an email.') }}</flux:text>
    </div>

    <form wire:submit="search" class="flex max-w-3xl items-end gap-3">
        <div class="min-w-0 flex-1">
            <flux:input
                wire:model="query"
                :label="__('Search the catalogue')"
                :placeholder="__('Name or SKU')"
                autocomplete="off"
                data-test="combo-search"
            />
            <flux:error name="query" />
        </div>

        <flux:button type="submit" icon="magnifying-glass" data-test="combo-search-button">
            <span wire:loading.remove wire:target="search">{{ __('Search') }}</span>
            <span wire:loading wire:target="search">{{ __('Searching…') }}</span>
        </flux:button>
    </form>

    @if ($notice)
        <flux:callout variant="danger" icon="x-circle" :heading="__('Could not continue')" :text="$notice" />
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="flex flex-col gap-3">
            <flux:heading>{{ __('Search results') }}</flux:heading>

            @if ($searched && $results === [])
                <flux:text>{{ __('No products matched that search.') }}</flux:text>
            @elseif (! $searched)
                <flux:text>{{ __('Search by product name or SKU.') }}</flux:text>
            @endif

            <div class="flex flex-col gap-2">
                @foreach ($results as $product)
                    <div wire:key="combo-result-{{ $product['id'] }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-md bg-white">
                            @if (str_starts_with($product['image'], 'data:image/'))
                                <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" class="size-full object-contain" />
                            @else
                                <flux:icon name="photo" class="size-6 text-zinc-400" />
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-zinc-800 dark:text-white">{{ $product['name'] }}</div>
                            @if ($product['sku'] !== '')
                                <flux:text size="sm">{{ $product['sku'] }}</flux:text>
                            @endif
                        </div>

                        <flux:button
                            type="button"
                            size="sm"
                            wire:click="addProduct({{ $product['id'] }})"
                            :disabled="$this->hasProduct($product['id'])"
                            data-test="add-product-{{ $product['id'] }}"
                        >
                            {{ $this->hasProduct($product['id']) ? __('Added') : __('Add') }}
                        </flux:button>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="flex flex-col gap-3">
            <div>
                <flux:heading>{{ __('Selected products') }}</flux:heading>
                <flux:text>{{ __(':count of :max selected', ['count' => count($selected), 'max' => $this->productLimit()]) }}</flux:text>
            </div>

            @if ($selected === [])
                <flux:text>{{ __('Add the products that belong in this offer.') }}</flux:text>
            @endif

            <div class="flex flex-col gap-2">
                @foreach ($selected as $product)
                    <div wire:key="combo-selected-{{ $product['id'] }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-md bg-white">
                            @if (str_starts_with($product['image'], 'data:image/'))
                                <img src="{{ $product['image'] }}" alt="" class="size-full object-contain" />
                            @else
                                <flux:icon name="photo" class="size-6 text-zinc-400" />
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-zinc-800 dark:text-white">{{ $product['name'] }}</div>
                            @if ($product['sku'] !== '')
                                <flux:text size="sm">{{ $product['sku'] }}</flux:text>
                            @endif
                        </div>

                        <flux:button type="button" size="sm" icon="x-mark" wire:click="removeProduct({{ $product['id'] }})" data-test="remove-product-{{ $product['id'] }}">
                            {{ __('Remove') }}
                        </flux:button>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <flux:button
                    type="button"
                    variant="primary"
                    icon="photo"
                    wire:click="create"
                    wire:loading.attr="disabled"
                    wire:target="create"
                    :disabled="count($selected) < 2"
                    data-test="create-combo-image"
                >
                    <span wire:loading.remove wire:target="create">{{ __('Create combo image') }}</span>
                    <span wire:loading wire:target="create">{{ __('Creating the combo image…') }}</span>
                </flux:button>

                <flux:text wire:loading wire:target="create">{{ __('This can take a minute.') }}</flux:text>
            </div>

            @if ($image)
                <div class="flex flex-col gap-3">
                    <div class="overflow-hidden rounded-xl bg-black p-4">
                        <img
                            src="{{ route('midmeds-asset-generation.image', $image) }}"
                            alt="{{ __('Combo image') }}"
                            class="mx-auto max-h-[32rem] w-full object-contain"
                            data-test="combo-image"
                        />
                    </div>

                    <div>
                        <flux:button :href="route('midmeds-asset-generation.image.download', $image)" icon="arrow-down-tray" data-test="download-combo-image">
                            {{ __('Download image') }}
                        </flux:button>
                    </div>
                </div>
            @endif
        </section>
    </div>
</div>
