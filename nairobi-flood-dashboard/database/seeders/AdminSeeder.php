<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Credentials come from .env so no password is stored in the code
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        if (!$email || !$password || strlen($password) < 12) {
            throw new RuntimeException('Set ADMIN_EMAIL and ADMIN_PASSWORD (at least 12 characters) in .env before seeding.');
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'System Admin',
                'role' => 'admin',
                'password' => Hash::make($password),
                'google_id' => null,
                'avatar' => null
            ]
        );
    }
}
