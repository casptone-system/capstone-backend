<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$users = App\Models\User::with('roles')->get();
echo 'users=' . $users->count() . PHP_EOL;
foreach ($users as $u) {
    echo $u->email . ' | ' . ($u->roles->pluck('name')->join(',') ?: 'NO_ROLE') . PHP_EOL;
}
