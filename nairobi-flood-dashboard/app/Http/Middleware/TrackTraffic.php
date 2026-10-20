<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TrackTraffic
{
    public function handle(Request $request, Closure $next)
    {
        // Exclude API, Auth, and Admin routes
        if ($request->isMethod('get') && !$request->is('api/*') && !$request->is('auth/*') && !$request->is('admin/*')) {
            
            // Check if current user is an admin
            $isAdmin = Auth::check() && Auth::user()->role === 'admin';

            // Only count visits for non-admin users and unauthenticated guest visitors
            if (!$isAdmin && !$request->session()->has('visited_today')) {
                DB::table('traffic')->upsert(
                    ['date' => now()->toDateString(), 'visits' => 1],
                    ['date'],
                    ['visits' => DB::raw('traffic.visits + 1')]
                );
                $request->session()->put('visited_today', true);
            }
        }

        return $next($request);
    }
}