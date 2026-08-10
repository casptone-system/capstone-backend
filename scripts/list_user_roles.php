<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;

$users = User::with('roles')->get();
foreach ($users as $u) {
    $roleNames = $u->roles->pluck('name')->toArray();
    echo sprintf("%d | %s <%s> | roles: %s\n", $u->id, $u->name, $u->email, implode(',', $roleNames));
}

echo "Total users: " . $users->count() . "\n";
