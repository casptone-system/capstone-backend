<?php

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditApiActions
{
    public function __construct(protected AuditLogService $auditLogService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->auditLogService->log($request, null, $exception);
            throw $exception;
        }

        $this->auditLogService->log($request, $response);

        return $response;
    }
}
