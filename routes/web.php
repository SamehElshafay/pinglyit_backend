<?php

use App\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// See SeoController's docblock for why these list user_website's URLs
// (config('pingly.frontend_url')) from a Laravel route, and the same-host
// caveat that comes with that.
Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);
Route::get('/robots.txt', [SeoController::class, 'robots']);
