<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = new GuzzleHttp\Client(['base_uri' => 'http://127.0.0.1:8000']);
$emails = ['superadmin@example.com','dean@example.com','program-chair@example.com','faculty@example.com','area-in-charge@example.com','qa@example.com','vpaa-di@example.com'];

foreach ($emails as $email) {
    try {
        $resp = $client->post('/api/login', ['json' => ['email' => $email, 'password' => 'Password123!']]);
        echo $email . ' => ' . $resp->getStatusCode() . PHP_EOL;
        echo (string) $resp->getBody() . PHP_EOL;
    } catch (Exception $e) {
        echo $email . ' => ERROR ' . $e->getMessage() . PHP_EOL;
    }
}
