<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== CHECKING DATABASE STATE ===\n\n";

// Check roles
$roles = \Spatie\Permission\Models\Role::all();
echo "Roles in database: " . $roles->count() . "\n";
if ($roles->count() > 0) {
    echo "  - " . $roles->pluck('name')->implode("\n  - ") . "\n";
} else {
    echo "  (NONE - Seeder didn't run!)\n";
}

// Check permissions
$permissions = \Spatie\Permission\Models\Permission::all();
echo "\nPermissions in database: " . $permissions->count() . "\n";
if ($permissions->count() > 0) {
    echo "  - " . $permissions->pluck('name')->implode("\n  - ") . "\n";
} else {
    echo "  (NONE - Seeder didn't run!)\n";
}

// Check dean role specifically
$deanRole = \Spatie\Permission\Models\Role::where('name', 'Dean')->first();
if ($deanRole) {
    $deanPerms = $deanRole->permissions()->pluck('name')->toArray();
    echo "\nDean Role Permissions: " . count($deanPerms) . "\n";
    echo "  - " . implode("\n  - ", $deanPerms) . "\n";
    
    if (in_array('access-college-dashboard', $deanPerms)) {
        echo "\n✓ access-college-dashboard permission IS assigned to Dean role\n";
    } else {
        echo "\n✗ access-college-dashboard permission IS NOT assigned to Dean role\n";
    }
} else {
    echo "\n✗ Dean role not found\n";
}

// Check colleges
$colleges = \App\Models\College::all();
echo "\nColleges in database: " . $colleges->count() . "\n";
if ($colleges->count() > 0) {
    foreach ($colleges as $college) {
        echo "  - {$college->name} (ID: {$college->id}, Code: {$college->code})\n";
    }
} else {
    echo "  (NONE)\n";
}
?>
