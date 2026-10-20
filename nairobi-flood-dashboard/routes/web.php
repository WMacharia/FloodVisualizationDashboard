<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MapController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;

// The main frontend view
Route::get('/', function () {
    return view('dashboard');
});

// The internal API endpoints feeding the map
Route::get('/api/flood-zones', [MapController::class, 'getFloodZones']);
Route::get('/api/matatu-routes', [MapController::class, 'getMatatuRoutes']);
Route::get('/api/calculate-route', [MapController::class, 'calculateRoute']);

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
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');