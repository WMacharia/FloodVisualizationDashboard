<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && Auth::user()->role === 'admin') {
            return $next($request);
        }

        // One sign-in for everyone: guests go to Google and come back here afterwards
        if (!Auth::check()) {
            return redirect()->guest(route('login.google'));
        }
        return redirect('/')->with('error', 'The System Console is for administrators only.');
    }
}