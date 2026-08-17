<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== LISTING ALL USERS AND THEIR ROLES ===\n\n";

$users = \App\Models\User::with('roles')->get();
echo 'Total Users: ' . $users->count() . "\n\n";

foreach ($users as $user) {
    $roles = $user->roles->pluck('name')->join(', ');
    echo "ID: {$user->id} | Name: {$user->name} | Email: {$user->email} | Roles: {$roles}\n";
}

echo "\n=== FINDING DEAN AND PROGRAM CHAIR ===\n\n";

$deans = \App\Models\User::role('Super Administrator')->get();
echo "Super Administrators: " . $deans->count() . "\n";
foreach ($deans as $dean) {
    echo "  - {$dean->name} (ID: {$dean->id})\n";
}

$chairs = \App\Models\User::role('Program Chair')->get();
echo "\nProgram Chairs: " . $chairs->count() . "\n";
foreach ($chairs as $chair) {
    echo "  - {$chair->name} (ID: {$chair->id})\n";
}
?>
