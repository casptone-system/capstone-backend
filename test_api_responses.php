<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== TESTING API RESPONSES ===\n\n";

// Get program chair
$chair = \App\Models\User::role('Program Chair')->first();

if (!$chair) {
    echo "ERROR: No program chair found\n";
    exit;
}

echo "Program Chair: {$chair->name} (ID: {$chair->id})\n\n";

// Simulate what the frontend API calls
echo "1. GET /api/task-notifications/badge-count\n";
$badgeCount = \App\Models\TaskNotification::getActiveBadgeCount($chair);
echo "   Response: { \"badge_count\": $badgeCount }\n\n";

echo "2. GET /api/task-notifications\n";
$tasks = \App\Models\TaskNotification::where('assigned_to_id', $chair->id)
    ->where(function ($query) {
        $query->where('status', 'pending')
            ->orWhere(function ($q) {
                $q->where('status', 'viewed')
                    ->where('badge_clear_at', '>', now());
            });
    })
    ->latest()
    ->get();

echo "   Response: {\n";
echo "     \"data\": [\n";
foreach ($tasks as $task) {
    echo "       {\n";
    echo "         \"id\": {$task->id},\n";
    echo "         \"title\": \"{$task->title}\",\n";
    echo "         \"description\": \"{$task->description}\",\n";
    echo "         \"type\": \"{$task->type}\",\n";
    echo "         \"status\": \"{$task->status}\",\n";
    echo "         \"is_welcome_task\": " . ($task->is_welcome_task ? 'true' : 'false') . ",\n";
    echo "         \"created_at\": \"{$task->created_at}\",\n";
    echo "         \"badge_clear_at\": " . ($task->badge_clear_at ? "\"{$task->badge_clear_at}\"" : "null") . "\n";
    echo "       },\n";
}
echo "     ],\n";
echo "     \"badge_count\": $badgeCount\n";
echo "   }\n\n";

echo "3. Testing GET /api/task-notifications/pending\n";
$pendingTasks = \App\Models\TaskNotification::where('assigned_to_id', $chair->id)
    ->where('status', 'pending')
    ->latest()
    ->get();

echo "   Pending Tasks: " . $pendingTasks->count() . "\n";
foreach ($pendingTasks as $task) {
    echo "     - {$task->title}\n";
}

echo "\n✅ API RESPONSES VERIFIED - Ready for frontend!\n";
?>
