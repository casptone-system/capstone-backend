<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;

$roles = Role::orderBy('id')->get();
foreach ($roles as $r) {
    $perms = $r->permissions()->pluck('name')->toArray();
    echo sprintf("%d | %s | perms:%d\n", $r->id, $r->name, count($perms));
    if (count($perms)) {
        echo "  - " . implode(", ", $perms) . "\n";
    }
}

echo "\nTotal roles: " . $roles->count() . "\n";

$usersWithRoles = DB::table('model_has_roles')->select('model_id','role_id')->get();
echo "Total role assignments: " . $usersWithRoles->count() . "\n";
