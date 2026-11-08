<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Admins and responders are signed out after a period without activity (default 30 minutes).
// Residents are not: their accounts can only save places.
//
// Background polling (the alert bell and the console's health check) does not count as
// activity, otherwise an unattended open tab would keep the session alive forever.
class StaffIdleTimeout
{
    public const KEY = 'auth.last_activity';

    private const BACKGROUND = ['api/alerts', 'admin/health'];

    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        if (!$user || !$user->isStaff()) {
            return $next($request);
        }

        $session = $request->session();
        $minutes = config('auth.staff_idle_minutes');
        $last = $session->get(self::KEY);

        if ($last && time() - $last > $minutes * 60) {
            AuditLog::record('auth.timeout', "{$user->roleLabel()} {$user->name} was signed out after {$minutes} minutes of inactivity", ['user_id' => $user->id]);
            Auth::logout();
            $session->invalidate();
            $session->regenerateToken();

            $message = "You were signed out after {$minutes} minutes of inactivity. Please sign in again.";
            if ($request->expectsJson()) {
                return response()->json(['error' => $message, 'signed_out' => true], 401);
            }
            return redirect('/')->with('status', $message);
        }

        if (!$last || !($request->isMethod('GET') && $request->is(...self::BACKGROUND))) {
            $session->put(self::KEY, time());
        }

        return $next($request);
    }
}
