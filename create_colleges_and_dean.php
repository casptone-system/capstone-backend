<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== CREATING COLLEGES ===\n\n";

$colleges_data = [
    ['code' => 'CAS', 'name' => 'College of Arts and Sciences'],
    ['code' => 'COE', 'name' => 'College of Engineering'],
    ['code' => 'CCS', 'name' => 'College of Computer Studies'],
    ['code' => 'CBM', 'name' => 'College of Business and Management'],
];

foreach ($colleges_data as $data) {
    $college = \App\Models\College::firstOrCreate($data);
    echo "✓ Created: {$college->name} (Code: {$college->code})\n";
}

echo "\n=== CREATING TEST DEAN USER ===\n\n";

// Get first college
$college = \App\Models\College::first();

// Create dean user
$dean = \App\Models\User::firstOrCreate(
    ['email' => 'testdean@example.com'],
    [
        'first_name' => 'Test',
        'last_name' => 'Dean',
        'password' => bcrypt('password123'),
        'college_id' => $college->id
    ]
);

echo "Created User: {$dean->name}\n";
echo "  Email: {$dean->email}\n";
echo "  College: {$college->name} (ID: {$college->id})\n";
echo "  College ID on user: " . $dean->college_id . "\n";

// Assign Dean role
$deanRole = \Spatie\Permission\Models\Role::where('name', 'Dean')->first();
if (!$dean->hasRole($deanRole)) {
    $dean->assignRole($deanRole);
    echo "  ✓ Dean role assigned\n";
} else {
    echo "  ✓ Dean role already assigned\n";
}

// Verify permissions
$perms = $dean->getPermissionNames()->toArray();
echo "\nDean User Permissions: " . count($perms) . "\n";
echo "  - " . implode("\n  - ", $perms) . "\n";

// Check authorization gate
echo "\n=== AUTHORIZATION CHECK ===\n\n";
echo "isDean(): " . ($dean->isDean() ? 'true' : 'false') . "\n";
echo "getEffectiveCollegeId(): " . ($dean->getEffectiveCollegeId() ?: '(empty)') . "\n";
echo "can('access-college-dashboard'): " . ($dean->can('access-college-dashboard') ? 'true' : 'false') . "\n";

if ($dean->can('access-college-dashboard')) {
    echo "\n✓✓✓ GATE CHECK PASSED - Dean should be able to access dashboard ✓✓✓\n";
} else {
    echo "\n✗✗✗ GATE CHECK FAILED - Still cannot access dashboard ✗✗✗\n";
}
?>
