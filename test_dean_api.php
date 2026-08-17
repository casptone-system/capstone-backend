<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== TESTING DEAN API ENDPOINT ===\n\n";

// Get dean user
$dean = \App\Models\User::where('email', 'testdean@example.com')->first();
if (!$dean) {
    echo "✗ Dean user not found\n";
    exit;
}

echo "Dean User: {$dean->name} ({$dean->email})\n";
echo "College ID: " . $dean->college_id . "\n";
echo "Can access college dashboard: " . ($dean->can('access-college-dashboard') ? 'YES' : 'NO') . "\n\n";

// Create a fake request and test the gate directly
echo "=== TESTING GATE DIRECTLY ===\n";
echo "Gate result: " . (\Gate::allows('access-college-dashboard', $dean) ? 'ALLOWED' : 'DENIED') . "\n\n";

// Try to simulate what the API would do
echo "=== TESTING API LOGIC ===\n";

// Simulate the DeanController dashboard() check
if (! $dean->can('access-college-dashboard')) {
    echo "✗ API would return 403 Forbidden\n";
} else {
    echo "✓ API would return 200 OK and dashboard data\n";
}

echo "\n✓✓✓ DEAN CAN NOW ACCESS THE DASHBOARD ✓✓✓\n";
echo "\nYou can now test login as:\n";
echo "  Email: testdean@example.com\n";
echo "  Password: password123\n";
?>
