<?php

use App\Http\Controllers\BiggestFishLogoController;
use App\Http\Controllers\FirstOrderDiamondLogoController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('first-order-diamonds', 'pages::first-order-diamonds')->name('first-order-diamonds');
    Route::get('first-order-diamonds/{diamond}/logo', FirstOrderDiamondLogoController::class)->name('first-order-diamonds.logo');
    Route::livewire('biggest-fish', 'pages::biggest-fish')->name('biggest-fish');
    Route::livewire('companies/{partner}', 'pages::company')->whereNumber('partner')->name('companies.show');
    Route::livewire('primary-care-spaces', 'pages::primary-care-spaces')->name('primary-care-spaces');
    Route::livewire('primary-care-spaces/{lead}', 'pages::gp-premises-project')->whereNumber('lead')->name('primary-care-spaces.show');
    Route::livewire('nhs-purchasing-patterns', 'pages::nhs-purchasing-patterns')->name('nhs-purchasing-patterns');
    Route::livewire('nhs-purchasing-patterns/directives/{directive}', 'pages::nhs-purchasing-directive')->whereNumber('directive')->name('nhs-purchasing-patterns.directives.show');
    Route::livewire('nhs-purchasing-patterns/{opportunity}', 'pages::nhs-purchasing-pattern')->whereNumber('opportunity')->name('nhs-purchasing-patterns.show');
    Route::get('biggest-fish/{fish}/logo', BiggestFishLogoController::class)->name('biggest-fish.logo');
});

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
    });

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
