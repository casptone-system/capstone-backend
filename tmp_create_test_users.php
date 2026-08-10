<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$super = App\Models\User::firstOrCreate(
    ['email' => 'superadmin@example.com'],
    ['name' => 'Super Admin', 'first_name' => 'Super', 'last_name' => 'Admin', 'password' => Illuminate\Support\Facades\Hash::make('Password123!'), 'email_verified_at' => now()]
);
$super->syncRoles(['Super Administrator']);

$roles = [
    'dean' => 'Dean',
    'program-chair' => 'Program Chair',
    'faculty' => 'Faculty',
    'area-in-charge' => 'Area In-Charge',
    'qa' => 'QA',
    'vpaa-di' => 'VPAA',
];

foreach ($roles as $slug => $roleName) {
    $email = $slug . '@example.com';
    $user = App\Models\User::firstOrCreate(
        ['email' => $email],
        ['name' => $roleName, 'first_name' => $roleName, 'last_name' => 'User', 'password' => Illuminate\Support\Facades\Hash::make('Password123!'), 'email_verified_at' => now()]
    );
    $user->syncRoles([$roleName]);
    echo $email . ' | ' . $roleName . PHP_EOL;
}
