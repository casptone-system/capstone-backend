<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use App\Models\User;

// Create a user
$user = User::factory()->create(['password' => bcrypt('secret123'), 'email_verified_at' => now()]);

// Build a login request
$loginReq = Request::create('/api/login', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], json_encode(['email' => $user->email, 'password' => 'secret123']));
$loginReq->headers->set('Content-Type', 'application/json');

$response = $kernel->handle($loginReq);
echo "Login status: " . $response->getStatusCode() . PHP_EOL;
$body = (string) $response->getContent();
echo "Login body: " . $body . PHP_EOL;
$data = json_decode($body, true);
$token = $data['data']['challenge_token'] ?? null;

// First resend
$resendReq = Request::create('/api/auth/resend-2fa', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], json_encode(['challenge_token' => $token]));
$resendReq->headers->set('Content-Type', 'application/json');
$res = $kernel->handle($resendReq);
echo "First resend status: " . $res->getStatusCode() . PHP_EOL;
echo "First resend body: " . (string) $res->getContent() . PHP_EOL;

// Immediate second resend
$res2Req = Request::create('/api/auth/resend-2fa', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], json_encode(['challenge_token' => $token]));
$res2Req->headers->set('Content-Type', 'application/json');
$res2 = $kernel->handle($res2Req);
echo "Second resend status: " . $res2->getStatusCode() . PHP_EOL;
echo "Second resend body: " . (string) $res2->getContent() . PHP_EOL;

// note: assumes DB/migrations are already in a usable state for this environment
