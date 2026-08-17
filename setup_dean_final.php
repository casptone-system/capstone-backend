<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== SETTING UP TEST DEAN USER ===\n\n";

// Ensure colleges exist
$colleges_data = [
    ['code' => 'CAS', 'name' => 'College of Arts and Sciences'],
    ['code' => 'COE', 'name' => 'College of Engineering'],
    ['code' => 'CCS', 'name' => 'College of Computer Studies'],
    ['code' => 'CBM', 'name' => 'College of Business and Management'],
];

foreach ($colleges_data as $data) {
    \App\Models\College::firstOrCreate($data);
}

// Get or create dean user
$college = \App\Models\College::first();
$dean = \App\Models\User::updateOrCreate(
    ['email' => 'testdean@example.com'],
    [
        'first_name' => 'Test',
        'last_name' => 'Dean',
        'password' => bcrypt('password123'),
        'college_id' => $college->id
    ]
);

// Assign Dean role
$deanRole = \Spatie\Permission\Models\Role::where('name', 'Dean')->first();
if (!$dean->hasRole($deanRole)) {
    $dean->assignRole($deanRole);
}

// Re-sync permissions from role to user directly
\Artisan::call('permission:cache-reset');

$dean = \App\Models\User::where('email', 'testdean@example.com')->first();

echo "User: {$dean->first_name} {$dean->last_name}\n";
echo "Email: {$dean->email}\n";
echo "College: {$college->name} (ID: {$college->id})\n";
echo "Role: Dean\n\n";

echo "=== AUTHORIZATION CHECK ===\n";
echo "isDean(): " . ($dean->isDean() ? 'YES' : 'NO') . "\n";
echo "college_id: " . $dean->college_id . "\n";
echo "getEffectiveCollegeId(): " . $dean->getEffectiveCollegeId() . "\n";
echo "can('access-college-dashboard'): " . ($dean->can('access-college-dashboard') ? 'YES' : 'NO') . "\n\n";

if ($dean->can('access-college-dashboard')) {
    echo "✓✓✓ SUCCESS - Dean can access college dashboard ✓✓✓\n";
} else {
    echo "✗✗✗ FAILED - Dean cannot access college dashboard ✗✗✗\n";
}

echo "\n=== LOGIN CREDENTIALS ===\n";
echo "Email: testdean@example.com\n";
echo "Password: password123\n";
?>
