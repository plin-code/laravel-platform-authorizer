<?php

use Illuminate\Support\Facades\Route;
use PlinCode\PlatformAuthorizer\Http\Controllers\CallbackController;
use PlinCode\PlatformAuthorizer\Http\Controllers\RedirectController;

Route::middleware((array) config('platform-authorizer.middleware'))
    ->prefix((string) config('platform-authorizer.route_prefix'))
    ->group(function (): void {
        Route::get('redirect', RedirectController::class)->name('platform-authorizer.redirect');
        Route::get('callback', CallbackController::class)->name('platform-authorizer.callback');
    });
