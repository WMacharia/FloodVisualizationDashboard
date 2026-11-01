<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Usage: ->middleware('role:responder,admin')
class HasRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        if (Auth::check() && in_array(Auth::user()->role, $roles, true)) {
            return $next($request);
        }

        return response()->json(['error' => 'You do not have permission to do this.'], Auth::check() ? 403 : 401);
    }
}
