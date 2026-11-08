<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// "Sudo mode" for sensitive actions (changing roles, deleting accounts): the staff member must
// have signed in with Google within the last few minutes (default 15). Otherwise they are
// asked to confirm it is them by signing in with the same Google account again.
class RequireRecentSignIn
{
    public const KEY = 'auth.confirmed_at';

    public static function confirmedUntil(Request $request): ?int
    {
        $at = $request->session()->get(self::KEY);
        $until = $at ? $at + config('auth.reconfirm_minutes') * 60 : null;
        return $until && $until > time() ? $until : null;
    }

    public function handle(Request $request, Closure $next)
    {
        if (self::confirmedUntil($request)) {
            return $next($request);
        }

        $message = 'For your security, confirm it is you (sign in with Google again) before making this change.';
        if ($request->expectsJson()) {
            return response()->json(['error' => $message, 'reconfirm_url' => route('auth.reconfirm')], 423);
        }
        return back()->with('error', $message)->with('reconfirm_needed', true);
    }
}
