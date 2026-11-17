<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireRecentSignIn;
use App\Http\Middleware\StaffIdleTimeout;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

// Everyone (residents, responders and administrators) signs in with Google, so the app stores
// no passwords. Google handles the password and 2-Step Verification; the app decides the role.
class AuthController extends Controller
{
    private const RECONFIRM = 'auth.reconfirm';

    public function redirectToGoogle(Request $request)
    {
        if ($wrongHost = $this->sameHostAsCallback($request)) {
            return $wrongHost;
        }
        $request->session()->forget(self::RECONFIRM);
        return Socialite::driver('google')->redirect();
    }

    // Sensitive actions: sign in with the same Google account again ("sudo mode")
    public function reconfirm(Request $request)
    {
        if ($wrongHost = $this->sameHostAsCallback($request)) {
            return $wrongHost;
        }
        $user = $request->user();
        $return = (string) $request->query('return', '');
        $request->session()->put(self::RECONFIRM, [
            'user_id' => $user->id,
            'at' => time(),
            'return' => preg_match('#^/(?!/)#', $return) ? $return : ($user->isAdmin() ? '/admin/dashboard' : '/'),
        ]);

        return Socialite::driver('google')
            ->with(['prompt' => 'select_account', 'login_hint' => $user->email])
            ->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        $pending = $request->session()->pull(self::RECONFIRM);
        $reconfirm = $pending && Auth::id() === $pending['user_id'] && time() - $pending['at'] < 600 ? $pending : null;

        if ($request->filled('error')) {
            return $this->failed($request, 'Sign-in was cancelled.', 'cancelled at Google (' . Str::limit($request->query('error'), 40) . ')', $reconfirm);
        }

        try {
            // Checks the OAuth "state" value, which stops forged sign-in links (CSRF)
            $google = Socialite::driver('google')->user();
        } catch (InvalidStateException $e) {
            return $this->failed($request, 'That sign-in link has expired. Please try again.', 'invalid or expired OAuth state', $reconfirm);
        } catch (Throwable $e) {
            report($e);
            return $this->failed($request, 'Google sign-in failed. Please try again.', 'error talking to Google', $reconfirm);
        }

        $email = strtolower((string) $google->getEmail());
        if ($email === '' || !($google->user['email_verified'] ?? false)) {
            return $this->failed($request, 'Your Google account email is not verified, so it cannot be used here.', "unverified email {$email}", $reconfirm);
        }

        if ($reconfirm) {
            return $this->finishReconfirm($request, $google->getId(), $email, $reconfirm);
        }

        $user = User::where('email', $email)->first();
        if ($user && $user->google_id && $user->google_id !== $google->getId()) {
            return $this->failed($request, 'This email is linked to a different Google account. Contact the system administrator.', "Google account mismatch for {$email}");
        }

        $user ??= new User(['email' => $email, 'role' => 'user']);
        $user->fill([
            'name' => $google->getName() ?: Str::before($email, '@'),
            'google_id' => $google->getId(),
            'avatar' => $google->getAvatar(),
        ]);
        $promoted = in_array($email, config('auth.admin_emails'), true) && $user->role !== 'admin';
        if ($promoted) {
            $user->role = 'admin';
        }
        $user->save();

        Auth::login($user);   // also gives the session a new id (prevents session fixation)
        $request->session()->put(StaffIdleTimeout::KEY, time());
        $request->session()->put(RequireRecentSignIn::KEY, time());   // a fresh sign-in counts as confirmed

        if ($promoted) {
            AuditLog::record('role.granted', "{$user->name} ({$email}) became an administrator (listed in ADMIN_EMAILS)", ['user_id' => $user->id]);
        }
        if ($user->isStaff()) {
            AuditLog::record('auth.signin', "{$user->roleLabel()} {$user->name} signed in with Google", ['user_id' => $user->id]);
        }

        // Back to where they started: the System Console if they opened /admin, otherwise the map
        return redirect()->intended('/');
    }

    private function finishReconfirm(Request $request, string $googleId, string $email, array $pending)
    {
        $user = $request->user();
        if (!$user->google_id || $user->google_id !== $googleId) {
            AuditLog::record('auth.reconfirm_failed', "{$user->name} tried to confirm with a different Google account ({$email})", ['user_id' => $user->id]);
            return redirect($pending['return'])->with('error', "Please confirm with the Google account you signed in with ({$user->email}).")->with('reconfirm_needed', true);
        }

        $request->session()->put(RequireRecentSignIn::KEY, time());
        $request->session()->put(StaffIdleTimeout::KEY, time());
        AuditLog::record('auth.reconfirmed', "{$user->name} confirmed their identity for sensitive actions", ['user_id' => $user->id]);
        $minutes = config('auth.reconfirm_minutes');
        return redirect($pending['return'])->with('status', "Identity confirmed. You can change roles and accounts for the next {$minutes} minutes. Please repeat your change.");
    }

    private function failed(Request $request, string $message, string $reason, ?array $reconfirm = null)
    {
        AuditLog::record('auth.failed', "Google sign-in failed: {$reason}");
        if ($reconfirm) {
            return redirect($reconfirm['return'])->with('error', $message);
        }
        return redirect('/')->with('error', $message);
    }

    // The OAuth state lives in the session cookie, so the sign-in must start on the same
    // host Google returns to (e.g. 127.0.0.1 vs localhost would always fail the state check)
    private function sameHostAsCallback(Request $request)
    {
        $parts = parse_url((string) config('services.google.redirect'));
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $origin !== $request->getSchemeAndHttpHost() ? redirect($origin . $request->getRequestUri()) : null;
    }

    public function logout(Request $request)
    {
        $this->signOut($request);
        return redirect('/');
    }

    private function signOut(Request $request): void
    {
        $user = $request->user();
        if ($user && $user->isStaff()) {
            AuditLog::record('auth.signout', "{$user->roleLabel()} {$user->name} signed out", ['user_id' => $user->id]);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
