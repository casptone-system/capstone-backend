<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('adams.email_verification_enabled')) {
            return $next($request);
        }

        $user = $request->user('api') ?? $request->user();

        if ($user && $user->email_verified_at === null) {
            return response()->json([
                'success' => false,
                'message' => 'Verify your email address before using ADAMS.',
                'code' => 'email_unverified',
            ], 403);
        }

        return $next($request);
    }
}
