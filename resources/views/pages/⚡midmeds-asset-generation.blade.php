<?php

use App\Actions\CreateComboImage;
use App\Actions\SaveComboImageToGallery;
use App\Models\ComboImage;
use App\Models\OdooConnection;
use App\Models\User;
use App\Services\MidMedsProductSearch;
use App\Services\OdooClient;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('MidMeds Asset Generation')] class extends Component {
    use WithPagination;
    public string $query = '';

    public bool $searched = false;

    public ?string $notice = null;

    public ?string $image = null;

    public string $effect = 'none';

    public string $horizontalPadding = '0';

    public string $verticalPadding = '0';

    public bool $logo = false;

    public string $logoCorner = 'top-left';

    public string $badgeText = '';

    public string $badgeStyle = 'none';

    public string $extraPrompt = '';

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

        $this->validate([
            'badgeText' => ['nullable', 'string', 'max:40'],
            'extraPrompt' => ['nullable', 'string', 'max:500'],
        ], [
            'badgeText.max' => 'Badge text can be up to 40 characters.',
            'extraPrompt.max' => 'Extra prompt can be up to 500 characters.',
        ]);

        try {
            $this->image = $combo->handle(
                array_values(array_unique($ids)),
                $this->image,
                $this->effect,
                (int) $this->horizontalPadding,
                (int) $this->verticalPadding,
                $this->logo,
                $this->logoCorner,
                $this->badgeText,
                $this->badgeStyle,
                $this->extraPrompt,
            );
        } catch (\RuntimeException $exception) {
            $this->notice = $exception->getMessage();
            Flux::toast(variant: 'danger', text: $this->notice);

            return;
        }

        Flux::toast(variant: 'success', text: 'Combo image ready.');
    }

    /**
     * Save the current image to the gallery and download it.
     */
    public function downloadCurrent(SaveComboImageToGallery $gallery): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! is_string($this->image) || ! Str::isUuid($this->image)) {
            return;
        }

        try {
            $saved = $gallery->remember($this->image, $user);
        } catch (\RuntimeException $exception) {
            $this->notice = $exception->getMessage();
            Flux::toast(variant: 'danger', text: $this->notice);

            return;
        }

        unset($this->gallery);

        $this->js('window.location.href = '.Js::from(route('midmeds-asset-generation.gallery.download', $saved)));
    }

    /**
     * @return LengthAwarePaginator<int, ComboImage>
     */
    #[Computed]
    public function gallery(): LengthAwarePaginator
    {
        return ComboImage::query()->with('user')->latest()->paginate(12, pageName: 'gallery');
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
        <flux:text>{{ __('Search the MidMeds catalogue and pick the products in an offer. ChatGPT groups their photos into one PNG with a transparent background, ready for the website or an email.') }}</flux:text>
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

            <div class="flex flex-wrap items-end gap-3">
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

                <flux:radio.group wire:model="effect" variant="segmented" data-test="combo-effect">
                    <flux:radio value="none" :label="__('None')" />
                    <flux:radio value="shadow" :label="__('Shadow')" />
                    <flux:radio value="reflection" :label="__('Reflection')" />
                </flux:radio.group>

                <flux:select wire:model="horizontalPadding" :label="__('Horizontal padding')" class="w-44" data-test="horizontal-padding">
                    @foreach (\App\Actions\CreateComboImage::PADDING as $padding)
                        <flux:select.option value="{{ $padding }}">{{ $padding === 0 ? __('None') : __(':percent% each side', ['percent' => $padding]) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="verticalPadding" :label="__('Vertical padding')" class="w-48" data-test="vertical-padding">
                    @foreach (\App\Actions\CreateComboImage::PADDING as $padding)
                        <flux:select.option value="{{ $padding }}">{{ $padding === 0 ? __('None') : __(':percent% top and bottom', ['percent' => $padding]) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:text wire:loading wire:target="create">{{ __('This can take a minute.') }}</flux:text>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <flux:checkbox wire:model.live="logo" :label="__('MidMeds logo')" data-test="combo-logo" />

                @if ($logo)
                    <flux:select wire:model="logoCorner" :label="__('Logo corner')" class="w-44" data-test="logo-corner">
                        @foreach (\App\Actions\CreateComboImage::LOGO_CORNERS as $corner => $label)
                            <flux:select.option value="{{ $corner }}">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input wire:model="badgeText" :label="__('Badge text')" placeholder="3 for 2" class="w-44" data-test="badge-text" />

                <flux:select wire:model="badgeStyle" :label="__('Badge style')" class="w-44" data-test="badge-style">
                    @foreach (\App\Actions\CreateComboImage::BADGE_STYLES as $style => $label)
                        <flux:select.option value="{{ $style }}">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:textarea
                wire:model="extraPrompt"
                :label="__('Extra prompt')"
                :description="__('Optional. Anything else you want added or changed in this image.')"
                rows="3"
                data-test="extra-prompt"
            />

            @if ($image)
                <div class="flex flex-col gap-3">
                    <div
                        class="overflow-hidden rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
                        style="background-color: #ffffff; background-image: linear-gradient(45deg, #d4d4d8 25%, transparent 25%), linear-gradient(-45deg, #d4d4d8 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #d4d4d8 75%), linear-gradient(-45deg, transparent 75%, #d4d4d8 75%); background-size: 20px 20px; background-position: 0 0, 0 10px, 10px -10px, -10px 0;"
                    >
                        <img
                            src="{{ route('midmeds-asset-generation.image', $image) }}"
                            alt="{{ __('Combo image') }}"
                            class="mx-auto max-h-[32rem] w-full object-contain"
                            data-test="combo-image"
                        />
                    </div>

                    <div>
                        <flux:button type="button" wire:click="downloadCurrent" icon="arrow-down-tray" data-test="download-combo-image">
                            {{ __('Download image') }}
                        </flux:button>
                    </div>
                </div>
            @endif
        </section>
    </div>

    <section class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Gallery') }}</flux:heading>

        @if ($this->gallery->isEmpty())
            <flux:text>{{ __('Images you download are kept here.') }}</flux:text>
        @else
            <div class="grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));">
                @foreach ($this->gallery as $saved)
                    <div wire:key="combo-gallery-{{ $saved->id }}" class="flex flex-col gap-2">
                        <div
                            class="overflow-hidden rounded-xl border border-zinc-200 p-3 dark:border-zinc-700"
                            style="background-color: #ffffff; background-image: linear-gradient(45deg, #d4d4d8 25%, transparent 25%), linear-gradient(-45deg, #d4d4d8 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #d4d4d8 75%), linear-gradient(-45deg, transparent 75%, #d4d4d8 75%); background-size: 20px 20px; background-position: 0 0, 0 10px, 10px -10px, -10px 0;"
                        >
                            <img
                                src="{{ route('midmeds-asset-generation.gallery', $saved) }}"
                                alt="{{ __('Saved combo image') }}"
                                class="mx-auto w-full object-contain"
                                style="height: 10rem;"
                            />
                        </div>

                        <flux:text size="sm">
                            {{ $saved->created_at?->timezone(config('app.timezone'))->format('j M Y, H:i') }}
                            @if ($saved->user !== null)
                                · {{ $saved->user->name }}
                            @endif
                        </flux:text>

                        <div>
                            <flux:button size="sm" :href="route('midmeds-asset-generation.gallery.download', $saved)" icon="arrow-down-tray">
                                {{ __('Download') }}
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{ $this->gallery->links() }}
        @endif
    </section>
</div>
