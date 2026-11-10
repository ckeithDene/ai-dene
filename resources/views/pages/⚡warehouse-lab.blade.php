<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Warehouse Lab')] class extends Component {
};
?>

<div class="fixed inset-0 top-14 lg:top-0 lg:left-64">
    <iframe
        title="{{ __('Warehouse Lab') }}"
        src="{{ route('warehouse-lab.files', ['path' => 'index.html']) }}"
        class="block h-full w-full border-0 bg-white"
    ></iframe>
</div>
