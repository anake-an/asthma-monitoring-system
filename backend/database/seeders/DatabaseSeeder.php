<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Local development only: creates one login with a random password.
 *
 * Production installs never seed. Register an account in the dashboard instead.
 * (A fixed password here would be a login the whole internet knows, since the repo is public.)
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Refusing to seed in production. Register an account in the dashboard instead.');

            return;
        }

        $email = 'dev@respirosync.test';
        $password = Str::password(16, symbols: false);

        User::updateOrCreate(
            ['email' => $email],
            ['name' => 'Local Developer', 'password' => Hash::make($password)]
        );

        $this->command?->info("Local dev account: {$email} / {$password} (shown once)");
    }
}
