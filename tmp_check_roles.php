<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$emails = ['superadmin@example.com','dean@example.com','program-chair@example.com','faculty@example.com','area-in-charge@example.com','qa@example.com','vpaa-di@example.com'];
foreach ($emails as $email) {
    $user = App\Models\User::where('email', $email)->first();
    echo $email . ' => ' . ($user ? 'found' : 'missing') . PHP_EOL;
}
