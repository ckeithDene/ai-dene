<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('MidMeds Asset Generation')] class extends Component {
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('MidMeds Asset Generation') }}</flux:heading>
        <flux:subheading>{{ __('Generate product assets for the MidMeds shop.') }}</flux:subheading>
    </div>
</div>
