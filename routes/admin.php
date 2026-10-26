<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::redirect('admin', 'admin/odoo');

    Route::livewire('admin/odoo', 'pages::admin.odoo-connection')->name('admin.odoo');
    Route::livewire('admin/ai-models', 'pages::admin.ai-models')->name('admin.ai-models');
});
