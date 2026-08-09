<?php

use App\Http\Controllers\Api\AccreditationAreaController;
use App\Http\Controllers\Api\AccreditationCycleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CollegeController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeanController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['security', 'audit.api'])->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/email/check', [AuthController::class, 'checkSmtpSettings']);
    Route::post('/auth/email/resend', [AuthController::class, 'resendVerificationEmail']);
    Route::post('/auth/verify-2fa', [AuthController::class, 'verifyTwoFactor']);
    Route::post('/auth/resend-2fa', [AuthController::class, 'resendTwoFactor']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('/auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');
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

    Route::get('dean/dashboard', [DeanController::class, 'dashboard']);
    Route::get('dean/programs', [DeanController::class, 'programs']);

    Route::get('users', [UserController::class, 'index']);

    // Super Administrator user management and administration
    Route::get('admin/dashboard', [UserController::class, 'dashboard']);
    Route::get('admin/users', [UserController::class, 'index']);
    Route::post('admin/users', [UserController::class, 'store']);
    Route::get('admin/users/{id}', [UserController::class, 'show']);
    Route::put('admin/users/{id}', [UserController::class, 'update']);
    Route::delete('admin/users/{id}', [UserController::class, 'destroy']);
    Route::post('admin/users/{id}/restore', [UserController::class, 'restore']);
    Route::post('admin/users/{id}/lock', [UserController::class, 'lock']);
    Route::post('admin/users/{id}/unlock', [UserController::class, 'unlock']);
    Route::post('admin/users/{id}/activate', [UserController::class, 'activate']);
    Route::post('admin/users/{id}/deactivate', [UserController::class, 'deactivate']);
    Route::post('admin/users/{id}/reset-password', [UserController::class, 'resetPassword']);

    Route::get('admin/roles', [UserController::class, 'roles']);
    Route::post('admin/roles', [UserController::class, 'storeRole']);
    Route::get('admin/roles/{id}/permissions', [UserController::class, 'rolePermissions']);
    Route::get('admin/permissions', [UserController::class, 'permissions']);
    Route::post('admin/roles/{id}/permissions', [UserController::class, 'assignPermissions']);

    Route::get('admin/system/settings', [SystemController::class, 'settings']);
    Route::post('admin/system/backup', [SystemController::class, 'backup']);

    Route::get('admin/audit-logs', [
        	App\Http\Controllers\Api\AuditController::class,
        'index'
    ]);
    Route::get('admin/login-history', [
        	App\Http\Controllers\Api\AuditController::class,
        'loginHistory'
    ]);
    Route::get('admin/sessions', [
        	App\Http\Controllers\Api\AuditController::class,
        'sessions'
    ]);

    // Join team using invitation code
    Route::post('/teams/join', [AuthController::class, 'joinTeam']);
    // Teams management (Program Chairs / Admins can create teams and codes)
    Route::apiResource('teams', \App\Http\Controllers\Api\TeamController::class)->only(['index', 'store', 'show']);

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
