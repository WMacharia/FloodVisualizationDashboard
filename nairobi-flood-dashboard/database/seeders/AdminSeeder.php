<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@floodviz.com'],
            [
                'name' => 'System Admin',
                'role' => 'admin',
                'password' => Hash::make('AdminPass123!'),
                'google_id' => null,
                'avatar' => null
            ]
        );
    }
}