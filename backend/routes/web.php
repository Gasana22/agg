<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// This is an API-only backend with no session-based login page. Laravel's
// auth middleware still builds a redirect to a route named 'login' for
// guests it doesn't detect as expecting JSON (e.g. a request missing an
// Accept header) — without this route that redirect construction itself
// throws. Route::has('login') is satisfied by this and requests still get
// a clean 401 JSON body, matching every other /api/* response.
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');
