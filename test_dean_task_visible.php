<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Get dean and program chair
$dean = \App\Models\User::role('Dean')->first() ?: \App\Models\User::role('superadmin')->first();
$chair = \App\Models\User::role('Program Chair')->first();

if (!($dean && $chair)) {
    echo "ERROR: Could not find dean or program chair user\n";
    echo "Available Dean roles: " . \App\Models\User::role('Dean')->count() . "\n";
    echo "Available Chair roles: " . \App\Models\User::role('Program Chair')->count() . "\n";
    exit;
}

echo "=== TESTING DEAN ASSIGN TASK TO PROGRAM CHAIR ===\n\n";

// Step 1: Dean creates task
$task = \App\Models\TaskNotification::create([
    'assigned_by_id' => $dean->id,
    'assigned_to_id' => $chair->id,
    'title' => 'Test Task from Dean',
    'description' => 'This is a test task to verify program chair can see it',
    'type' => 'document_upload',
    'status' => 'pending',
    'badge_clear_hours' => 48,
]);

echo "✓ Task Created by Dean:\n";
echo "  Task ID: {$task->id}\n";
echo "  Title: {$task->title}\n";
echo "  Status: {$task->status}\n";
echo "  Assigned to: {$chair->name} (ID: {$chair->id})\n\n";

// Step 2: Get badge count for program chair
$badgeCount = \App\Models\TaskNotification::getActiveBadgeCount($chair);
echo "✓ Program Chair Badge Count: {$badgeCount}\n\n";

// Step 3: Get all active tasks for program chair
$tasks = \App\Models\TaskNotification::getActiveForUser($chair)->get();
echo "✓ Active Tasks for Program Chair: " . $tasks->count() . "\n";
foreach ($tasks as $t) {
    echo "  - {$t->title} (Status: {$t->status})\n";
}

echo "\n✅ VERIFICATION COMPLETE\n";
echo "Program Chair SHOULD see this task in the NotificationBell component\n";

// Step 4: Check API endpoint directly
echo "\n=== TESTING API ENDPOINT ===\n";
$taskData = $task->toArray();
echo "Task JSON (as API returns):\n";
echo json_encode($taskData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
?>
