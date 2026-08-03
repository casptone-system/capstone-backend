<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditLogService
{
    public function log(Request $request, ?Response $response = null, ?Throwable $exception = null): void
    {
        $user = $request->user('api') ?? $request->user();

        $payload = [
            'event' => $this->determineAction($request),
            'method' => $request->method(),
            'path' => $request->path(),
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status' => $response?->getStatusCode(),
            'exception' => $exception?->getMessage(),
        ];

        Log::channel(config('logging.audit_channel', 'audit'))->info('API action audited', $payload);
    }

    protected function determineAction(Request $request): string
    {
        $path = strtolower($request->path());

        if (str_contains($path, 'upload') || str_contains($path, 'replace')) {
            return 'upload';
        }

        if ($request->isMethod('delete')) {
            return 'delete';
        }

        if ($request->isMethod('put') || $request->isMethod('patch')) {
            return 'edit';
        }

        return 'modify';
    }
}
