<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Admin') }}">
            <flux:navlist.item :href="route('admin.odoo')" :current="request()->routeIs('admin.odoo')" wire:navigate>{{ __('Odoo connections') }}</flux:navlist.item>
            <flux:navlist.item :href="route('admin.ai-models')" :current="request()->routeIs('admin.ai-models')" wire:navigate>{{ __('AI Models') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
