<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Seed admin user with secure random password
        $adminPassword = config('app.env') === 'local'
            ? 'SecureAdmin123!'  // Strong default for local development
            : Str::random(32);   // Random for non-local environments

        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'email_verified_at' => now(),
                'password' => Hash::make($adminPassword),
                'role' => Role::Admin,
            ]
        );

        // Output password for local development
        if (config('app.env') === 'local') {
            $this->command->info('Admin User Created:');
            $this->command->info('  Email: admin@example.com');
            $this->command->info('  Password: ' . $adminPassword);
        } else {
            $this->command->warn('Admin user created with random password. Please reset via password recovery.');
        }

        // Seed test user for local/testing only
        if (in_array(config('app.env'), ['local', 'testing'], true)) {
            User::updateOrCreate(
                ['email' => 'test@example.com'],
                [
                    'name' => 'Test User',
                    'email_verified_at' => now(),
                    'password' => Hash::make('TestUser123!'),
                    'role' => Role::Analyst,
                ]
            );

            $this->command->info('Test User Created:');
            $this->command->info('  Email: test@example.com');
            $this->command->info('  Password: TestUser123!');
        }
    }
}
