<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\AuditLogDetail;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditLogService
{
    public function log(Request $request, ?Response $response = null, ?Throwable $exception = null): void
    {
        if ($this->shouldSkip($request)) {
            return;
        }

        DB::transaction(function () use ($request, $response, $exception): void {
            $user = $request->user('api') ?? $request->user();
            $email = $request->input('email') ?? $user?->email;
            $event = $this->determineAction($request, $response, $exception);
            $status = $this->determineStatus($response, $exception);

            if (in_array($event, ['login', 'logout', '2fa_success'], true)) {
                $historyUser = $user;
                if (! $historyUser && $email) {
                    $historyUser = User::query()->where('email', $email)->first();
                }

                $historyStatus = match ($event) {
                    'logout' => 'logout',
                    '2fa_success' => 'success',
                    default => $status === 'success' ? 'success' : 'failed',
                };

                $this->recordLoginHistory($historyUser?->id, $email ?? $historyUser?->email, $historyStatus, $request);

                if ($historyUser && $historyStatus === 'success') {
                    $historyUser->forceFill(['last_login_at' => now()])->save();
                }
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
        $explicit = $request->attributes->get('audit_event');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        if (preg_match('#api/board-of-action/\d+/download$#', $path) === 1) {
            return 'board_of_action_downloaded';
        }

        if (preg_match('#api/board-of-action/\d+/preview$#', $path) === 1) {
            return 'board_of_action_viewed';
        }

        if (preg_match('#api/board-of-action/\d+/replace$#', $path) === 1) {
            return 'board_of_action_replaced';
        }

        if ($request->isMethod('delete') && preg_match('#api/board-of-action/\d+$#', $path) === 1) {
            return 'board_of_action_deleted';
        }

        if ($request->isMethod('post') && $path === 'api/board-of-action') {
            return 'board_of_action_uploaded';
        }

        if ($request->isMethod('get') && preg_match('#api/board-of-action/\d+$#', $path) === 1) {
            return 'board_of_action_viewed';
        }

        if ($request->isMethod('post') && preg_match('#parameter-rows/\d+/links$#', $path) === 1) {
            return 'link_added';
        }

        if ($path === 'api/login') {
            return 'login';
        }

        if ($path === 'api/logout') {
            return 'logout';
        }

        if (preg_match('#(reviews|documents)/\d+/approve#', $path)) {
            return 'review_approved';
        }

        if (preg_match('#(reviews|documents)/\d+/request-revision#', $path)) {
            return 'review_revision_requested';
        }

        if (preg_match('#reviews/\d+/reject#', $path)) {
            return 'review_rejected';
        }

        if (preg_match('#reviews/\d+/submit#', $path)) {
            return 'review_submitted';
        }

        if (str_contains($path, 'upload') || str_contains($path, 'replace')) {
            return 'upload';
        }

        if ($request->isMethod('post') && preg_match('#documents$#', $path)) {
            return 'document_uploaded';
        }

        if ($request->isMethod('delete')) {
            return str_contains($path, 'documents') ? 'document_deleted' : 'delete';
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

    public function record(Request $request, string $event, ?string $path = null): void
    {
        $user = $request->user();

        AuditLog::create([
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'event' => $event,
            'method' => $request->method(),
            'path' => $path ?? $request->path(),
            'status' => 'success',
            'ip_address' => $request->ip(),
        ]);
    }

    protected function shouldSkip(Request $request): bool
    {
        if (! $request->isMethod('get')) {
            return false;
        }

        return in_array(strtolower($request->path()), [
            'api/notifications',
            'api/notifications/unread-count',
            'api/task-notifications',
            'api/task-notifications/badge-count',
        ], true);
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
