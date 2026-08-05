<?php

use App\Http\Controllers\Api\AccreditationAreaController;
use App\Http\Controllers\Api\AccreditationCycleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CollegeController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['security', 'audit.api'])->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
});

Route::middleware(['auth:sanctum', 'security', 'rbac', 'audit.api'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Colleges (GET /colleges, POST /colleges + full CRUD)
    Route::apiResource('colleges', CollegeController::class);

    // Programs (GET /programs, POST /programs + full CRUD)
    Route::apiResource('programs', ProgramController::class);

    // Accreditation Cycles (CRUD + history + dashboard)
    Route::get('accreditation-cycles/dashboard', [AccreditationCycleController::class, 'dashboard']);
    Route::get('accreditation-cycles/history/{program}', [AccreditationCycleController::class, 'history']);
    Route::apiResource('accreditation-cycles', AccreditationCycleController::class);

    // Accreditation Areas (CRUD + assign chair + manage members + progress)
    Route::post('accreditation-areas/{accreditationArea}/assign-chair', [AccreditationAreaController::class, 'assignChair']);
    Route::post('accreditation-areas/{accreditationArea}/members', [AccreditationAreaController::class, 'addMember']);
    Route::delete('accreditation-areas/{accreditationArea}/members/{member}', [AccreditationAreaController::class, 'removeMember']);
    Route::get('accreditation-areas/{accreditationArea}/progress', [AccreditationAreaController::class, 'progress']);
    Route::apiResource('accreditation-areas', AccreditationAreaController::class);

    // Tasks (CRUD + assign members + mark complete + progress)
    Route::post('tasks/{task}/assign-members', [TaskController::class, 'assignMembers']);
    Route::delete('tasks/{task}/assignments/{assignment}', [TaskController::class, 'removeAssignment']);
    Route::post('tasks/{task}/mark-complete', [TaskController::class, 'markComplete']);
    Route::get('tasks/{task}/progress', [TaskController::class, 'progress']);
    Route::apiResource('tasks', TaskController::class);

    // Documents (CRUD + upload + replace + versions + download)
    Route::post('documents/{document}/replace', [DocumentController::class, 'replace']);
    Route::get('documents/{document}/versions', [DocumentController::class, 'versions']);
    Route::get('documents/{document}/download', [DocumentController::class, 'download']);
    Route::apiResource('documents', DocumentController::class);

    // Reviews (CRUD + workflow: submit, approve, request-revision, reject, comments)
    Route::post('reviews/{review}/submit', [ReviewController::class, 'submit']);
    Route::post('reviews/{review}/approve', [ReviewController::class, 'approve']);
    Route::post('reviews/{review}/request-revision', [ReviewController::class, 'requestRevision']);
    Route::post('reviews/{review}/reject', [ReviewController::class, 'reject']);
    Route::get('reviews/{review}/comments', [ReviewController::class, 'comments']);
    Route::apiResource('reviews', ReviewController::class);

    // Notifications (list, show, mark read, mark all read, unread count, delete)
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::get('notifications/{id}', [NotificationController::class, 'show']);
    Route::post('notifications/{id}/mark-read', [NotificationController::class, 'markAsRead']);
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);
    Route::apiResource('notifications', NotificationController::class)->only(['index']);

    // Dashboard Analytics (real data from database queries)
    Route::get('dashboard', [DashboardController::class, 'index']);

    // Reports (compliance, program, college, area, accreditation + PDF/Excel exports)
    Route::get('reports', [ReportController::class, 'index']);
    Route::get('reports/compliance', [ReportController::class, 'compliance']);
    Route::get('reports/programs/{program}', [ReportController::class, 'program']);
    Route::get('reports/colleges/{college}', [ReportController::class, 'college']);
    Route::get('reports/areas/{area}', [ReportController::class, 'area']);
    Route::get('reports/accreditation-cycles/{cycle}', [ReportController::class, 'accreditation']);

    // Audit and login history reporting
    Route::get('audit-logs', [\App\Http\Controllers\Api\AuditController::class, 'index']);
    Route::get('audit-logs/summaries', [\App\Http\Controllers\Api\AuditController::class, 'summaries']);
    Route::get('login-history', [\App\Http\Controllers\Api\AuditController::class, 'loginHistory']);
});

Route::middleware(['security', 'audit.api'])->get('/health', function (Request $request) {
    return response()->json([
        'success' => true,
        'message' => 'API foundation is ready.',
    ]);
});
