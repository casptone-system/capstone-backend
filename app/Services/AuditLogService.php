<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\AuditLogDetail;
use App\Models\LoginHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditLogService
{
    public function log(Request $request, ?Response $response = null, ?Throwable $exception = null): void
    {
        DB::transaction(function () use ($request, $response, $exception): void {
            $user = $request->user('api') ?? $request->user();
            $email = $request->input('email') ?? $user?->email;
            $event = $this->determineAction($request, $response, $exception);
            $status = $this->determineStatus($response, $exception);

            if (in_array($event, ['login', 'logout'], true)) {
                $this->recordLoginHistory($user?->id, $email, $status, $request);
            }

            $auditLog = AuditLog::create([
                'user_id' => $user?->id,
                'user_email' => $email,
                'event' => $event,
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $status,
                'ip_address' => $request->ip(),
            ]);

            AuditLogDetail::create([
                'audit_log_id' => $auditLog->id,
                'user_agent' => $request->userAgent(),
                'exception' => $exception?->getMessage(),
            ]);
        });
    }

    protected function determineAction(Request $request, ?Response $response = null, ?Throwable $exception = null): string
    {
        $path = strtolower($request->path());

        if ($path === 'api/login') {
            return 'login';
        }

        if ($path === 'api/logout') {
            return 'logout';
        }

        if (str_contains($path, 'upload') || str_contains($path, 'replace')) {
            return 'upload';
        }

        if ($request->isMethod('delete')) {
            return 'delete';
        }

        if ($request->isMethod('put') || $request->isMethod('patch')) {
            return $exception ? 'error' : 'edit';
        }

        if ($request->isMethod('post')) {
            return $exception ? 'error' : 'create';
        }

        return 'modify';
    }

    protected function determineStatus(?Response $response = null, ?Throwable $exception = null): string
    {
        if ($exception !== null) {
            return method_exists($exception, 'getStatusCode') ? (string) $exception->getStatusCode() : 'error';
        }

        if ($response === null) {
            return 'info';
        }

        $code = $response->getStatusCode();

        if ($code >= 200 && $code < 300) {
            return 'success';
        }

        if ($code === 401) {
            return 'unauthorized';
        }

        if ($code === 403) {
            return 'forbidden';
        }

        if ($code >= 500) {
            return 'error';
        }

        return 'warning';
    }

    protected function recordLoginHistory(?int $userId, ?string $email, string $status, Request $request): void
    {
        $loginStatus = in_array($status, ['failed', 'success', 'logout'], true)
            ? $status
            : 'failed';

        LoginHistory::create([
            'user_id' => $userId,
            'email' => $email,
            'status' => $loginStatus,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'occurred_at' => now(),
        ]);
    }
}
