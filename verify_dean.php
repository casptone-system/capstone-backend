<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$deanRole = \Spatie\Permission\Models\Role::where('name', 'Dean')->first();
$deanUser = \App\Models\User::where('email', 'testdean@example.com')->first();

echo "=== Dean Role Verification ===\n";
if ($deanRole) {
    $perms = $deanRole->permissions()->pluck('name')->toArray();
    echo "Dean role found\n";
    echo "Total permissions: " . count($perms) . "\n";
    echo "Permissions: " . implode(", ", $perms) . "\n";
    
    if (in_array('access-college-dashboard', $perms)) {
        echo "✓ access-college-dashboard is assigned\n";
    } else {
        echo "✗ access-college-dashboard is MISSING\n";
    }
} else {
    echo "✗ Dean role not found\n";
}

echo "\n=== Dean User Verification ===\n";
if ($deanUser) {
    echo "Dean user found: " . $deanUser->name . "\n";
    echo "Email: " . $deanUser->email . "\n";
    echo "College ID: " . ($deanUser->college_id ?? 'NULL') . "\n";
    echo "Roles: " . $deanUser->getRoleNames()->implode(', ') . "\n";
    echo "Permissions: " . $deanUser->getPermissionNames()->implode(', ') . "\n";
} else {
    echo "✗ Dean user not found\n";
}
?>
