<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SystemMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// System console: health of the model and the site, advisories, user roles and the audit log
class AdminController extends Controller
{
    public function index(SystemMonitor $monitor)
    {
        $users = User::orderBy('created_at', 'desc')->get();
        $roleCounts = $users->countBy('role');
        $traffic = DB::table('traffic')->where('date', '>=', now()->subDays(30))->sum('visits');

        $advisories = Alert::where('kind', 'advisory')->with('creator:id,name')
            ->orderByDesc('created_at')->limit(20)->get();
        $audit = AuditLog::with('user:id,name,role')->orderByDesc('created_at')->limit(40)->get();

        return view('admin.dashboard', [
            'users' => $users,
            'totalUsers' => $users->count(),
            'roleCounts' => $roleCounts,
            'traffic' => $traffic,
            'advisories' => $advisories,
            'audit' => $audit,
            'health' => $this->health($monitor),
        ]);
    }

    // Polled by the console every 30 s
    public function healthJson(SystemMonitor $monitor)
    {
        return response()->json($this->health($monitor, fresh: true));
    }

    private function health(SystemMonitor $monitor, bool $fresh = false): array
    {
        $model = $fresh || !Cache::has('monitor:model') ? $monitor->checkModel() : Cache::get('monitor:model');
        return [
            'model' => $model,
            'requests' => SystemMonitor::requestStats(),
            'weather' => Cache::get('monitor:weather'),
            'open_system_alerts' => Alert::active()->where('kind', 'system')->count(),
            'active_advisories' => Alert::active()->where('kind', 'advisory')->count(),
        ];
    }

    public function updateRole(Request $request, User $user)
    {
        $v = $request->validate(['role' => ['required', Rule::in(['user', 'responder'])]]);
        if ($user->isAdmin()) {
            return back()->with('error', 'Administrator accounts cannot be changed here.');
        }
        $old = $user->role;
        $user->update(['role' => $v['role']]);
        AuditLog::record('role.changed', "Changed {$user->name}'s role from {$old} to {$v['role']}", ['user_id' => $user->id]);
        return back()->with('status', "{$user->name} is now a " . ($v['role'] === 'responder' ? 'responder' : 'resident') . '.');
    }

    public function destroy(User $user)
    {
        if ($user->isAdmin()) {
            return back()->with('error', 'Cannot delete a system administrator account.');
        }

        AuditLog::record('user.deleted', "Deleted the account of {$user->name} ({$user->email})", ['user_id' => $user->id]);
        $user->delete();
        return back()->with('status', 'User account deleted.');
    }

    public function withdraw(Alert $alert)
    {
        abort_unless($alert->kind === 'advisory', 404);
        $alert->resolve(Auth::user());
        AuditLog::record('advisory.withdrawn', "Withdrew advisory: {$alert->title}", ['alert_id' => $alert->id]);
        return back()->with('status', 'Advisory withdrawn.');
    }
}
