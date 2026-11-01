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
Route::prefix('api')->middleware('throttle:120,1')->group(function () {
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

// Standard User Google OAuth Routes
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('login.google');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);

// Admin Email/Password Login Routes (Now directly on /admin)
Route::get('/admin', [AuthController::class, 'showAdminLoginForm'])->name('admin.login');
Route::post('/admin', [AuthController::class, 'adminLogin'])->name('admin.login.submit');
Route::post('/admin/logout', [AuthController::class, 'adminLogout'])->name('admin.logout');

// Protected Admin Dashboard Routes
Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/admin/dashboard', [AdminController::class, 'index'])->name('admin.index');
    Route::delete('/admin/users/{user}', [AdminController::class, 'destroy'])->name('admin.users.destroy');
    Route::patch('/admin/users/{user}/role', [AdminController::class, 'updateRole'])->name('admin.users.role');
    Route::post('/admin/advisories/{alert}/withdraw', [AdminController::class, 'withdraw'])->name('admin.advisories.withdraw');
    Route::get('/admin/health', [AdminController::class, 'healthJson'])->name('admin.health');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');