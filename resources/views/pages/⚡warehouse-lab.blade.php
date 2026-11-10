<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Warehouse Lab')] class extends Component {
};
?>

<div class="flex h-[calc(100dvh-8rem)] min-h-[720px] w-full flex-1 flex-col">
    <iframe
        title="{{ __('Warehouse Lab') }}"
        src="{{ route('warehouse-lab.files', ['path' => 'index.html']) }}"
        class="h-full min-h-0 w-full flex-1 rounded-xl border border-zinc-200 bg-white dark:border-zinc-700"
    ></iframe>
</div>
