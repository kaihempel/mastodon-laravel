<?php

use Illuminate\Support\Facades\Route;
use KaiHempel\Mastodon\Http\OAuthController;

Route::group([
    'prefix' => config('mastodon.routes.prefix', 'mastodon'),
    'middleware' => config('mastodon.routes.middleware', ['web', 'auth']),
    'as' => 'mastodon.',
], function () {
    Route::post('connect', [OAuthController::class, 'connect'])->name('connect');
    Route::get('callback', [OAuthController::class, 'callback'])->name('callback');
    Route::delete('accounts/{account}', [OAuthController::class, 'disconnect'])->name('disconnect');
});
