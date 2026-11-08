<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MapController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\PlaceController;

// The main frontend view
Route::get('/', function () {
    return view('dashboard');
});

// Map data, proxied to the Python model server (scripts/model_api.py)
Route::prefix('api')->middleware('throttle:api')->group(function () {
    Route::get('/flood/predict', [MapController::class, 'predict']);
    Route::get('/flood/overlay.png', [MapController::class, 'overlay']);
    Route::get('/flood/zones', [MapController::class, 'zones']);
    Route::get('/flood/extent', [MapController::class, 'extent']);
    Route::get('/flood/point', [MapController::class, 'pointInfo']);
    Route::get('/flood/explain', [MapController::class, 'explain']);
    Route::get('/route', [MapController::class, 'route']);
    Route::get('/subcounties', [MapController::class, 'subcounties']);
    Route::get('/model', [MapController::class, 'modelInfo']);

    // On-screen alerts (guests see public advisories only)
    Route::get('/alerts', [AlertController::class, 'index']);
    Route::middleware('auth')->group(function () {
        Route::post('/alerts/{alert}/ack', [AlertController::class, 'acknowledge']);
        Route::get('/places', [PlaceController::class, 'index']);
        Route::post('/places', [PlaceController::class, 'store']);
        Route::delete('/places/{place}', [PlaceController::class, 'destroy']);
    });
    Route::middleware('role:responder,admin')->group(function () {
        Route::post('/advisories', [AlertController::class, 'storeAdvisory']);
        Route::post('/advisories/{alert}/withdraw', [AlertController::class, 'withdraw']);
    });
});

// Sign-in: everyone uses Google (no passwords are stored). Rate limited per IP / user.
Route::middleware('throttle:sign-in')->group(function () {
    Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('login.google');
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
});
// Staff confirm it is them (Google again) before sensitive actions
Route::get('/auth/reconfirm', [AuthController::class, 'reconfirm'])
    ->middleware(['auth', 'throttle:reconfirm'])->name('auth.reconfirm');
// Activity ping from open staff pages (keeps an in-use session from timing out)
Route::post('/auth/activity', fn () => response()->noContent())->middleware(['auth', 'throttle:activity']);

// Protected Admin Dashboard Routes. There is no separate staff sign-in page: everyone uses the
// map's Google sign-in, and the role on the account decides what they can open.
Route::middleware('admin')->group(function () {
    Route::get('/admin', fn () => redirect()->route('admin.index'));
    Route::get('/admin/dashboard', [AdminController::class, 'index'])->name('admin.index');
    Route::post('/admin/advisories/{alert}/withdraw', [AdminController::class, 'withdraw'])->name('admin.advisories.withdraw');
    Route::get('/admin/health', [AdminController::class, 'healthJson'])->name('admin.health');

    // Changing who can do what needs a recent Google sign-in
    Route::middleware('reconfirm')->group(function () {
        Route::delete('/admin/users/{user}', [AdminController::class, 'destroy'])->name('admin.users.destroy');
        Route::patch('/admin/users/{user}/role', [AdminController::class, 'updateRole'])->name('admin.users.role');
    });
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
// Old console sign-out address (pages opened before sign-in was merged): same sign-out. A plain
// visit (GET) only goes to the map, because signing out must be a POST with the CSRF token.
Route::post('/admin/logout', [AuthController::class, 'logout']);
Route::get('/admin/logout', fn () => redirect('/'));