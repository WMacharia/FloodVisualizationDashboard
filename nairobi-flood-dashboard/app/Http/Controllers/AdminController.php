<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function index()
    {
        $users = User::orderBy('created_at', 'desc')->get();
        $totalUsers = $users->count();

        // 30-Day total visit count from PostGIS/PostgreSQL traffic table
        $traffic = DB::table('traffic')
            ->where('date', '>=', now()->subDays(30))
            ->sum('visits');

        return view('admin.dashboard', compact('users', 'totalUsers', 'traffic'));
    }

    public function destroy(User $user)
    {
        if ($user->role === 'admin') {
            return back()->with('error', 'Cannot delete a system administrator account.');
        }

        $user->delete();
        return back()->with('success', 'User account successfully deleted.');
    }
}