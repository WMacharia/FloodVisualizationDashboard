<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    // Google OAuth Methods (Standard Users)
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        $googleUser = Socialite::driver('google')->stateless()->user();
        
        $user = User::updateOrCreate(
            ['email' => $googleUser->email],
            [
                'name' => $googleUser->name,
                'google_id' => $googleUser->id,
                'avatar' => $googleUser->avatar,
            ]
        );

        Auth::login($user);

        return redirect($user->role === 'admin' ? '/admin' : '/');
    }

    // Email/Password Methods (Admin Users)
    public function showAdminLoginForm()
    {
        // If they are already logged in as admin, send them to the dashboard
        if (Auth::check() && Auth::user()->role === 'admin') {
            return redirect()->route('admin.index'); 
        }

        return view('admin.login');
    }

    public function adminLogin(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

            if (Auth::user()->role === 'admin') {
                // Redirect straight to the admin management dashboard
                return redirect()->intended('/admin/dashboard');
            }

            // Reject standard users trying to use the admin portal
            Auth::logout();
            return back()->withErrors(['email' => 'Access denied. Admin credentials required.']);
        }

        return back()->withErrors(['email' => 'Invalid email or password.']);
    }

    public function adminLogout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}