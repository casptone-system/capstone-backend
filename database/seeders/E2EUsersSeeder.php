<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

class E2EUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $credentialsPath = base_path('.e2e-credentials.json');

        $roles = [
            'vpaa',
            'dean',
            'program-chair',
            'faculty',
            'area-in-charge',
            'qa',
            'superadmin',
        ];

        // If credentials file exists, use it (idempotent)
        if (file_exists($credentialsPath)) {
            $data = json_decode(file_get_contents($credentialsPath), true) ?? [];
            $this->command->info('E2E credentials file found, ensuring users exist...');
            foreach ($data as $item) {
                $email = $item['email'];
                $role = $item['role'];
                $user = User::where('email', $email)->first();
                if (! $user) {
                    $user = User::create([
                        'name' => $item['first_name'] . ' ' . ($item['last_name'] ?? ''),
                        'first_name' => $item['first_name'] ?? 'E2E',
                        'middle_name' => $item['middle_name'] ?? null,
                        'last_name' => $item['last_name'] ?? 'User',
                        'email' => $email,
                        'password' => Hash::make($item['password']),
                        'email_verified_at' => now(),
                    ]);
                    $this->command->info("Created user: {$email}");
                } else {
                    $this->command->info("User exists: {$email} (id={$user->id})");
                }

                Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
                $user->assignRole($role);

                // Ensure a personal access token exists for tests and record it
                if (empty($item['token'])) {
                    $token = $user->createToken('e2e-token')->plainTextToken;
                    $item['token'] = $token;
                }
            }

            // rewrite file to ensure tokens are present
            file_put_contents($credentialsPath, json_encode($data, JSON_PRETTY_PRINT));
            $this->command->info('E2E credentials ensured at: ' . $credentialsPath);
            return;
        }

        // Create credentials file freshly
        $timestamp = time();
        $out = [];
        foreach ($roles as $role) {
            $email = sprintf('e2e+%s+%s@e2e.test', $role, $timestamp);
            // If an existing real user already uses this email, pick a different one and skip modification
            if (User::where('email', $email)->exists()) {
                $this->command->warn("Email {$email} already exists; skipping creation for this exact address.");
                $existing = User::where('email', $email)->first();
                $out[] = [
                    'role' => $role,
                    'email' => $email,
                    'first_name' => 'E2E',
                    'last_name' => ucfirst($role),
                    'password' => null,
                    'id' => $existing->id,
                    'note' => 'existing_user_skipped',
                ];
                continue;
            }

            $password = Str::random(12);

            $user = User::create([
                'name' => 'E2E ' . ucfirst($role),
                'first_name' => 'E2E',
                'middle_name' => null,
                'last_name' => ucfirst($role),
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);

            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $user->assignRole($role);

            $token = $user->createToken('e2e-token')->plainTextToken;

            $out[] = [
                'role' => $role,
                'email' => $email,
                'first_name' => 'E2E',
                'last_name' => ucfirst($role),
                'password' => $password,
                'id' => $user->id,
                'token' => $token,
            ];

            $this->command->info("Created E2E user: {$email} (id={$user->id})");
        }

        // write credentials to a gitignored file at repo root
        file_put_contents($credentialsPath, json_encode($out, JSON_PRETTY_PRINT));
        $this->command->info('Wrote E2E credentials to: ' . $credentialsPath);
    }
}
