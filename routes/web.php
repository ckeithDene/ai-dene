<?php

use App\Http\Controllers\FirstOrderDiamondLogoController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('first-order-diamonds', 'pages::first-order-diamonds')->name('first-order-diamonds');
    Route::get('first-order-diamonds/{diamond}/logo', FirstOrderDiamondLogoController::class)->name('first-order-diamonds.logo');
});

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
    });

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
