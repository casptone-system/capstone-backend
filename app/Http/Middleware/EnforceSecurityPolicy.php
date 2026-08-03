<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $blockedIps = array_filter((array) config('security.blocked_ips', []));
        if ($request->ip() !== null && in_array($request->ip(), $blockedIps, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied by security policy.',
            ], 403);
        }

        if ((bool) config('security.force_https', false) && ! $this->isSecureRequest($request)) {
            return response()->json([
                'success' => false,
                'message' => 'HTTPS is required for this API.',
            ], 403);
        }

        return $next($request);
    }

    protected function isSecureRequest(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        $forwardedProto = $request->header('X-Forwarded-Proto');
        if ($forwardedProto) {
            foreach (explode(',', $forwardedProto) as $proto) {
                if (trim($proto) === 'https') {
                    return true;
                }
            }
        }

        return $request->getScheme() === 'https';
    }
}
