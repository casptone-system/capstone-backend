<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== VERIFYING TASK_NOTIFICATIONS TABLE ===\n\n";

$tables = \Illuminate\Support\Facades\Schema::getTables();
$hasTaskNotifications = false;

foreach ($tables as $table) {
    if ($table['name'] === 'task_notifications') {
        $hasTaskNotifications = true;
        echo "✓ task_notifications table exists\n\n";
        
        $columns = \Illuminate\Support\Facades\Schema::getColumns('task_notifications');
        echo "Columns:\n";
        foreach ($columns as $column) {
            echo "  - {$column['name']} ({$column['type_name']})\n";
        }
        break;
    }
}

if (!$hasTaskNotifications) {
    echo "✗ task_notifications table NOT found\n";
    echo "\nAvailable tables:\n";
    foreach ($tables as $table) {
        echo "  - {$table['name']}\n";
    }
}
?>
