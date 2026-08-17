<?php
/**
 * Script to ensure all roles have their correct permissions assigned
 * This is useful after adding new permissions or fixing permission assignments
 */

require 'bootstrap/app.php';

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Define all permissions that roles should have
$rolePermissions = [
    'Faculty' => [
        'view dashboard',
        'upload documents',
        'submit reviews',
    ],
    'Dean' => [
        'view dashboard',
        'access-college-dashboard',
        'manage reviews',
        'approve reviews',
        'review reports',
        'manage teams',
        'manage documents',
    ],
    'Program Chair' => [
        'view dashboard',
        'manage teams',
        'invite faculty',
        'assign chairs',
        'review reports',
        'manage reviews',
        'request revisions',
    ],
    'Area In-Charge' => [
        'view dashboard',
        'manage reviews',
        'request revisions',
        'review reports',
    ],
    'QA' => [
        'view dashboard',
        'review reports',
        'view audit logs',
    ],
    'VPAA' => [
        'view dashboard',
        'approve reviews',
        'review reports',
        'view audit logs',
    ],
    'Super Administrator' => [
        'view dashboard',
        'manage users',
        'manage teams',
        'invite faculty',
        'assign chairs',
        'manage documents',
        'submit reviews',
        'manage reviews',
        'approve reviews',
        'request revisions',
        'review reports',
        'view audit logs',
        'view login history',
    ],
];

echo "=== Synchronizing Role Permissions ===\n\n";

foreach ($rolePermissions as $roleName => $permissions) {
    $role = Role::where('name', $roleName)->first();
    
    if (!$role) {
        echo "❌ Role '{$roleName}' not found. Skipping.\n";
        continue;
    }
    
    echo "Processing role: {$roleName}\n";
    
    $assignedCount = 0;
    foreach ($permissions as $permissionName) {
        $permission = Permission::firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web'
        ]);
        
        // Only assign if not already assigned
        if (!$role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
            $assignedCount++;
            echo "  ✓ Assigned: {$permissionName}\n";
        } else {
            echo "  - Already has: {$permissionName}\n";
        }
    }
    
    echo "  Total assigned in this pass: {$assignedCount}\n";
    echo "  Total permissions for this role: " . $role->permissions()->count() . "\n\n";
}

echo "=== Permission Synchronization Complete ===\n";

// Verify dean users specifically
echo "\n=== Verifying Dean Users ===\n";
$deanRole = Role::where('name', 'Dean')->first();
if ($deanRole) {
    $deanUsers = \App\Models\User::whereHas('roles', fn($q) => $q->where('name', 'Dean'))->get();
    echo "Found " . $deanUsers->count() . " dean users:\n";
    
    foreach ($deanUsers as $user) {
        echo "  - {$user->name} (ID: {$user->id}, College: " . ($user->college_id ?? 'NONE') . ")\n";
        $userPerms = $user->getPermissionNames();
        echo "    Permissions: " . $userPerms->implode(', ') . "\n";
    }
} else {
    echo "Dean role not found!\n";
}

?>
