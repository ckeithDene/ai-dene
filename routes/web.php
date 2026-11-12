<?php

use App\Http\Controllers\BiggestFishLogoController;
use App\Http\Controllers\FirstOrderDiamondLogoController;
use App\Http\Controllers\WarehouseLabAssetController;
use App\Http\Controllers\WarehouseLabOrderController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('invitations/{invitation}', 'pages::auth.accept-invitation')->name('invitations.accept');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('first-order-diamonds', 'pages::first-order-diamonds')->name('first-order-diamonds');
    Route::get('first-order-diamonds/{diamond}/logo', FirstOrderDiamondLogoController::class)->name('first-order-diamonds.logo');
    Route::livewire('biggest-fish', 'pages::biggest-fish')->name('biggest-fish');
    Route::livewire('google-shopping-suggestions', 'pages::google-shopping-suggestions')->name('google-shopping-suggestions');
    Route::livewire('companies/{partner}', 'pages::company')->whereNumber('partner')->name('companies.show');
    Route::livewire('primary-care-spaces', 'pages::primary-care-spaces')->name('primary-care-spaces');
    Route::livewire('primary-care-spaces/{lead}', 'pages::gp-premises-project')->whereNumber('lead')->name('primary-care-spaces.show');
    Route::livewire('nhs-purchasing-patterns', 'pages::nhs-purchasing-patterns')->name('nhs-purchasing-patterns');
    Route::livewire('nhs-purchasing-patterns/directives/{directive}', 'pages::nhs-purchasing-directive')->whereNumber('directive')->name('nhs-purchasing-patterns.directives.show');
    Route::livewire('nhs-purchasing-patterns/{opportunity}', 'pages::nhs-purchasing-pattern')->whereNumber('opportunity')->name('nhs-purchasing-patterns.show');
    Route::livewire('warehouse-lab', 'pages::warehouse-lab')->name('warehouse-lab');
    Route::get('warehouse-lab/moves', [WarehouseLabOrderController::class, 'moves'])->name('warehouse-lab.moves');
    Route::get('warehouse-lab/orders', [WarehouseLabOrderController::class, 'index'])->name('warehouse-lab.orders.index');
    Route::get('warehouse-lab/orders/{order}', [WarehouseLabOrderController::class, 'show'])->whereNumber('order')->name('warehouse-lab.orders.show');
    Route::get('warehouse-lab/files/{path?}', WarehouseLabAssetController::class)
        ->where('path', '.*')
        ->name('warehouse-lab.files');
    Route::get('biggest-fish/{fish}/logo', BiggestFishLogoController::class)->name('biggest-fish.logo');
});

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
    });

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
