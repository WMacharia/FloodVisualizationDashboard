<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

// Administrators sign in with Google, so no password is stored. This seeder marks every
// address in ADMIN_EMAILS (.env) as an administrator; the same can be done one account at
// a time with `php artisan admin:grant you@example.com`.
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $emails = config('auth.admin_emails');
        if (!$emails) {
            throw new RuntimeException('Set ADMIN_EMAILS (comma-separated Google accounts) in .env before seeding.');
        }
        foreach ($emails as $email) {
            Artisan::call('admin:grant', ['email' => $email]);
            $this->command?->line(trim(Artisan::output()));
        }
    }
}
