<?php
require 'bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dean = \App\Models\User::where('email', 'like', '%dean%')->first();
if ($dean) {
    echo "Dean: " . $dean->name . " - College ID: " . ($dean->college_id ?? 'NULL') . "\n";
    $roles = $dean->getRoleNames();
    echo "Roles: " . $roles->implode(', ') . "\n";
    $deanRole = \Spatie\Permission\Models\Role::where('name', 'Dean')->first();
    if ($deanRole) {
        echo "Dean role permissions: ";
        $perms = $deanRole->permissions()->pluck('name');
        echo $perms->implode(', ') . "\n";
        echo "Total Dean role permissions: " . $perms->count() . "\n";
    }
} else {
    echo "No dean user found\n";
}
?>
