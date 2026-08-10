<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$emails = ['superadmin@example.com','dean@example.com','program-chair@example.com','faculty@example.com','area-in-charge@example.com','qa@example.com','vpaa-di@example.com'];
foreach ($emails as $email) {
    $u = App\Models\User::where('email', $email)->first();
    $roles = $u ? $u->roles->pluck('name')->join(',') : 'MISSING';
    echo $email . ' | ' . ($u ? 'FOUND' : 'MISSING') . ' | ' . $roles . PHP_EOL;
}

echo '---PROGRAMS---' . PHP_EOL;
foreach (App\Models\Program::orderBy('id')->get(['id','name','code']) as $p) {
    echo $p->id . ':' . $p->name . ' (' . $p->code . ')' . PHP_EOL;
}

echo '---BS/IT LIKE PROGRAMS---' . PHP_EOL;
foreach (App\Models\Program::where('name', 'like', '%BS%')->orWhere('name', 'like', '%IT%')->orWhere('code', 'like', '%BS%')->orderBy('id')->get(['id','name','code']) as $p) {
    echo $p->id . ':' . $p->name . ' (' . $p->code . ')' . PHP_EOL;
}
