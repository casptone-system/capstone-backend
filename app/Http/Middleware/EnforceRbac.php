<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceRbac
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api') ?? $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $requiredRoles = array_filter((array) config('security.required_roles', []));
        $requiredPermissions = array_filter((array) config('security.required_permissions', []));

        if ($requiredRoles && ! $user->hasAnyRole($requiredRoles)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have the required role to access this resource.',
            ], 403);
        }

        if ($requiredPermissions && ! $user->hasAnyPermission($requiredPermissions)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have the required permission to access this resource.',
            ], 403);
        }

        return $next($request);
    }
}
