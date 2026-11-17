<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/*
| Administrator accounts are managed from the server, never from a web page.
| Whoever can run these commands controls the system (the "break-glass" route),
| so a stolen browser session cannot create new administrators.
*/

Artisan::command('admin:grant {email}', function (string $email) {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error("'{$email}' is not a valid email address.");
        return 1;
    }
    $user = User::firstOrNew(['email' => $email]);
    if ($user->exists && $user->isAdmin()) {
        $this->info("{$email} is already an administrator.");
        return 0;
    }
    $isNew = !$user->exists;
    $user->name ??= Str::before($email, '@');
    $user->role = 'admin';
    $user->save();
    AuditLog::record('role.granted', "{$user->name} ({$email}) was made an administrator from the command line", ['user_id' => $user->id]);
    $this->info("{$email} is now an administrator." . ($isNew ? ' They can sign in with that Google account at /admin.' : ''));
    return 0;
})->purpose('Make a Google account an administrator (creates the account if needed)');

Artisan::command('admin:revoke {email}', function (string $email) {
    $email = strtolower(trim($email));
    $user = User::where('email', $email)->first();
    if (!$user || !$user->isAdmin()) {
        $this->error("{$email} is not an administrator.");
        return 1;
    }
    try {
        $user->update(['role' => 'user', 'password' => null]);
    } catch (DomainException $e) {
        $this->error($e->getMessage());
        return 1;
    }
    AuditLog::record('role.revoked', "{$user->name} ({$email}) is no longer an administrator (command line)", ['user_id' => $user->id]);
    $this->info("{$email} is now a resident account.");
    if (in_array($email, config('auth.admin_emails'), true)) {
        $this->warn('This email is still listed in ADMIN_EMAILS in .env, so it will become an administrator again at its next sign-in. Remove it from there too.');
    }
    return 0;
})->purpose('Remove administrator rights (the last administrator cannot be removed)');

Artisan::command('admin:list', function () {
    $rows = User::where('role', 'admin')->orderBy('email')->get()->map(fn ($u) => [
        $u->email, $u->name,
        $u->google_id ? 'yes' : 'not yet',
        in_array(strtolower($u->email), config('auth.admin_emails'), true) ? 'yes' : 'no',
    ]);
    $this->table(['Email', 'Name', 'Has signed in with Google', 'In ADMIN_EMAILS'], $rows);
    foreach (config('auth.admin_emails') as $email) {
        if (!User::where('email', $email)->exists()) {
            $this->line("  {$email} (ADMIN_EMAILS) will become an administrator at first sign-in.");
        }
    }
})->purpose('List administrator accounts');
