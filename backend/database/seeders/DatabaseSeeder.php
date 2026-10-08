<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@asthma.local'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password123'),
            ]
        );
    }
}
