<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->app->make(DatabaseSeeder::class)->run();

        $this->assertSame(0, User::count());
    }

    public function test_local_seed_account_never_uses_a_known_password(): void
    {
        $this->app->make(DatabaseSeeder::class)->run();

        $user = User::sole();
        $this->assertSame('dev@respirosync.test', $user->email);
        $this->assertFalse(Hash::check('password123', $user->password));
    }
}
