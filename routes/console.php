<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::command('first-order-diamonds:check')
    ->hourly()
    ->withoutOverlapping(180)
    ->runInBackground();

Schedule::command('biggest-fish:check')
    ->hourly()
    ->withoutOverlapping(180)
    ->runInBackground();

Schedule::command('primary-care-spaces:check')
    ->daily()
    ->withoutOverlapping(180)
    ->runInBackground();

Schedule::command('nhs-purchasing-patterns:check')
    ->weekly()
    ->withoutOverlapping(180)
    ->runInBackground();
