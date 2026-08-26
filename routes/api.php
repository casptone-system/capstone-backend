<?php

use App\Http\Controllers\Api\AccreditationAreaController;
use App\Http\Controllers\Api\AccreditationCycleController;
use App\Http\Controllers\Api\AccreditationStructureController;
use App\Http\Controllers\Api\AccreditationWorkspaceController;
use App\Http\Controllers\Api\InstrumentTemplateController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChunkedUploadController;
use App\Http\Controllers\Api\CollegeController;
use App\Http\Controllers\Api\ProgramActiveLevelController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeanController;
use App\Http\Controllers\Api\FacultyAreaContentController;
use App\Http\Controllers\Api\FacultyTaskController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\QAController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\RoleStorageController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskNotificationController;
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
    Route::get('users/me/areas', [FacultyAreaContentController::class, 'myAreas']);
    Route::post('/me/profile-photo', [AuthController::class, 'updateProfilePhoto']);

    // Colleges (GET /colleges, POST /colleges + full CRUD)
    Route::apiResource('colleges', CollegeController::class);

    // Programs (GET /programs, POST /programs + full CRUD)
    Route::apiResource('programs', ProgramController::class);
    Route::get('programs/{program}/active-level', [ProgramActiveLevelController::class, 'show']);
    Route::put('programs/{program}/active-level', [ProgramActiveLevelController::class, 'update']);
    Route::delete('programs/{program}/members/{user}', [ProgramController::class, 'removeMember']);

    // Accreditation Cycles (CRUD + history + dashboard)
    Route::get('vpaa/dashboard', [AccreditationCycleController::class, 'vpaaDashboard']);
    Route::get('accreditation-cycles/dashboard', [AccreditationCycleController::class, 'dashboard']);
    Route::get('accreditation-cycles/level-status', [AccreditationCycleController::class, 'levelStatus']);
    Route::get('accreditation-cycles/history/{program}', [AccreditationCycleController::class, 'history']);
    Route::post('accreditation-cycles/{accreditationCycle}/acknowledge', [AccreditationCycleController::class, 'acknowledge']);
    Route::post('accreditation-cycles/{accreditationCycle}/forward-to-chair', [AccreditationCycleController::class, 'forwardToChair']);
    Route::post('accreditation-cycles/{accreditationCycle}/set-requirements', [AccreditationCycleController::class, 'setRequirements']);
    Route::post('accreditation-cycles/{accreditationCycle}/program-chair-setup', [AccreditationCycleController::class, 'programChairSetupInfo']);
    Route::post('accreditation-cycles/{accreditationCycle}/set-schedule', [AccreditationCycleController::class, 'setSchedule']);
    Route::get('accreditation-cycles/{accreditationCycle}/structure', [AccreditationStructureController::class, 'show']);
    Route::post('accreditation-cycles/{accreditationCycle}/structure', [AccreditationStructureController::class, 'store']);
    Route::post('accreditation-cycles/{accreditationCycle}/dean-validate', [AccreditationCycleController::class, 'deanValidate']);
    Route::post('accreditation-cycles/{accreditationCycle}/vpaa-monitor', [AccreditationCycleController::class, 'vpaaMonitor']);
    Route::apiResource('accreditation-cycles', AccreditationCycleController::class);

    // Accreditation Areas (CRUD + assign chair + manage members + progress)
    Route::post('accreditation-areas/{accreditationArea}/assign-chair', [AccreditationAreaController::class, 'assignChair']);
    Route::post('accreditation-areas/{accreditationArea}/assign-in-charge', [AccreditationStructureController::class, 'assignInCharge']);
    Route::get('accreditation-areas/{accreditationArea}/requirements', [AccreditationStructureController::class, 'requirements']);
    Route::get('accreditation-areas/{accreditationArea}/members', [AccreditationAreaController::class, 'getMembers']);
    Route::post('accreditation-areas/{accreditationArea}/members', [AccreditationAreaController::class, 'addMember']);
    Route::delete('accreditation-areas/{accreditationArea}/members/{member}', [AccreditationAreaController::class, 'removeMember']);
    Route::get('accreditation-areas/{accreditationArea}/progress', [AccreditationAreaController::class, 'progress']);
    Route::get('accreditation-areas/{accreditationArea}/parameters', [FacultyAreaContentController::class, 'parameters']);
    Route::post('accreditation-areas/{accreditationArea}/parameters', [FacultyAreaContentController::class, 'storeParameter']);
    Route::get('parameters/{parameter}/rows', [FacultyAreaContentController::class, 'rows']);
    Route::post('parameters/{parameter}/rows', [FacultyAreaContentController::class, 'storeRow']);
    Route::patch('parameter-rows/{parameterContentRow}/status', [FacultyAreaContentController::class, 'updateStatus']);
    Route::patch('parameter-rows/{parameterContentRow}/content', [FacultyAreaContentController::class, 'updateContent']);
    Route::delete('parameter-rows/{parameterContentRow}/documents', [FacultyAreaContentController::class, 'destroyRowDocuments']);
    Route::post('parameter-rows/{parameterContentRow}/submit', [FacultyAreaContentController::class, 'submitRow']);
    Route::delete('parameter-rows/{parameterContentRow}', [FacultyAreaContentController::class, 'destroyRow']);
    Route::get('program-chair/areas', [AccreditationAreaController::class, 'programChairAreas']);
    Route::get('program-chair/area-documents', [AccreditationAreaController::class, 'programChairAreaDocuments']);
    Route::get('program-chair/areas/{accreditationArea}/documents', [AccreditationAreaController::class, 'programChairAreaDocumentFiles']);
    Route::post('accreditation-areas/{accreditationArea}/set-members', [AccreditationAreaController::class, 'setMembers']);
    Route::post('accreditation-areas/{accreditationArea}/set-deadline', [AccreditationAreaController::class, 'setDeadline']);
    Route::post('accreditation-areas/{accreditationArea}/submit-review', [AccreditationAreaController::class, 'submitReview']);
    Route::post('accreditation-areas/submit-files', [AccreditationAreaController::class, 'submitFiles']);
    Route::apiResource('accreditation-areas', AccreditationAreaController::class);

    // Faculty Task Assignment (Stage 3)
    Route::post('accreditation-requirements/{requirement}/assign-faculty', [FacultyTaskController::class, 'assignFacultyToRequirement']);
    Route::get('faculty/tasks', [FacultyTaskController::class, 'getFacultyTasks']);
    Route::get('faculty/tasks/{task}', [FacultyTaskController::class, 'getFacultyTask']);
    Route::patch('faculty/tasks/{task}', [FacultyTaskController::class, 'updateFacultyTask']);
    Route::post('faculty/tasks/{task}/submit', [FacultyTaskController::class, 'submitFacultyTask']);
    Route::get('program-chair/tasks-pending-review', [FacultyTaskController::class, 'getProgramChairPendingReview']);
    Route::post('faculty/tasks/{task}/approve', [FacultyTaskController::class, 'approveFacultyTask']);
    Route::post('faculty/tasks/{task}/return', [FacultyTaskController::class, 'returnFacultyTask']);

    // Tasks (CRUD + assign members + mark complete + progress)
    Route::post('tasks/{task}/assign-members', [TaskController::class, 'assignMembers']);
    Route::delete('tasks/{task}/assignments/{assignment}', [TaskController::class, 'removeAssignment']);
    Route::post('tasks/{task}/mark-complete', [TaskController::class, 'markComplete']);
    Route::get('tasks/{task}/progress', [TaskController::class, 'progress']);
    Route::apiResource('tasks', TaskController::class);

    // Documents (CRUD + upload + replace + versions + download)
    Route::post('documents/{document}/approve', [DocumentController::class, 'approve']);
    Route::post('documents/{document}/request-revision', [DocumentController::class, 'requestRevision']);
    Route::post('documents/{document}/replace', [DocumentController::class, 'replace']);
    Route::get('documents/{document}/versions', [DocumentController::class, 'versions']);
    Route::get('documents/{document}/download', [DocumentController::class, 'download']);
    Route::get('documents/{document}/preview', [DocumentController::class, 'preview']);
    Route::apiResource('documents', DocumentController::class);

    Route::get('uploads/config', [ChunkedUploadController::class, 'config']);
    Route::post('uploads/initiate', [ChunkedUploadController::class, 'initiate']);
    Route::post('uploads/{upload}/chunks', [ChunkedUploadController::class, 'storeChunk']);
    Route::post('uploads/{upload}/complete', [ChunkedUploadController::class, 'complete']);
    Route::delete('uploads/{upload}', [ChunkedUploadController::class, 'destroy']);

    // Role-specific storage vaults
    Route::get('role-storage', [RoleStorageController::class, 'index']);
    Route::get('role-storage/storage', [RoleStorageController::class, 'storageSummary']);
    Route::post('role-storage/folders', [RoleStorageController::class, 'store']);
    Route::patch('role-storage/folders/{folder}/rename', [RoleStorageController::class, 'renameFolder']);
    Route::patch('role-storage/folders/{folder}/move', [RoleStorageController::class, 'moveFolder']);
    Route::post('role-storage/folders/{folder}/upload', [RoleStorageController::class, 'upload']);
    Route::patch('role-storage/files/{file}', [RoleStorageController::class, 'update']);
    Route::post('role-storage/files/{file}/favorite', [RoleStorageController::class, 'favoriteFile']);
    Route::post('role-storage/files/{file}/trash', [RoleStorageController::class, 'trashFile']);
    Route::post('role-storage/files/{file}/restore', [RoleStorageController::class, 'restoreFile']);
    Route::post('role-storage/files/{file}/link-evidence', [RoleStorageController::class, 'linkEvidence']);
    Route::get('role-storage/files/{file}/download', [RoleStorageController::class, 'download']);
    Route::delete('role-storage/files/{file}', [RoleStorageController::class, 'destroyFile']);

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
    Route::get('notifications/{id}/download-instrument', [NotificationController::class, 'downloadInstrumentFile']);
    Route::post('notifications/{id}/dismiss', [NotificationController::class, 'destroy']);
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);
    Route::apiResource('notifications', NotificationController::class)->only(['index']);

    // QA Dashboard and Reports (monitoring and viewing only)
    Route::get('qa/areas', [FacultyAreaContentController::class, 'qaAreas']);
    Route::get('qa/dashboard', [QAController::class, 'dashboard']);
    Route::get('qa/reports/program-readiness', [QAController::class, 'programReadinessReport']);
    Route::get('qa/reports/college-comparison', [QAController::class, 'collegeComparisonReport']);
    Route::get('qa/reports/at-risk-programs', [QAController::class, 'atRiskProgramsReport']);
    Route::get('qa/accreditations', [QAController::class, 'accreditationPrograms']);
    Route::get('qa/accreditations/{cycle}', [QAController::class, 'accreditationDetail']);

    Route::get('instrument-templates', [InstrumentTemplateController::class, 'index']);
    Route::get('instrument-templates/{instrumentTemplate}', [InstrumentTemplateController::class, 'show']);
    Route::post('instrument-templates', [InstrumentTemplateController::class, 'upsert']);
    Route::delete('instrument-templates/areas/{area}', [InstrumentTemplateController::class, 'destroyArea']);

    Route::get('accreditation-workspaces', [AccreditationWorkspaceController::class, 'index']);
    Route::post('accreditation-workspaces', [AccreditationWorkspaceController::class, 'store']);
    Route::get('accreditation-workspaces/{workspace}', [AccreditationWorkspaceController::class, 'show']);
    Route::get('accreditation-workspaces/{workspace}/progress', [AccreditationWorkspaceController::class, 'progress']);
    Route::post('accreditation-workspaces/{workspace}/areas/{area}/chair', [AccreditationWorkspaceController::class, 'assignChair']);
    Route::post('accreditation-workspaces/{workspace}/areas/{area}/members', [AccreditationWorkspaceController::class, 'addMember']);
    Route::delete('accreditation-workspaces/{workspace}/areas/{area}/members/{user}', [AccreditationWorkspaceController::class, 'removeMember']);
    Route::get('accreditation-workspaces/{workspace}/parameters/{parameter}', [AccreditationWorkspaceController::class, 'parameter']);
    Route::post('accreditation-workspaces/{workspace}/criteria/{requirement}/evidence', [AccreditationWorkspaceController::class, 'uploadEvidence']);
    Route::post('accreditation-workspaces/{workspace}/criteria/{requirement}/done', [AccreditationWorkspaceController::class, 'markDone']);
    Route::get('accreditation-workspaces/{workspace}/evidence/{evidence}/download', [AccreditationWorkspaceController::class, 'downloadEvidence']);
    Route::get('accreditation-workspaces/{workspace}/evidence/{evidence}/preview', [AccreditationWorkspaceController::class, 'previewEvidence']);

    // Dashboard Analytics (real data from database queries)
    Route::get('dashboard', [DashboardController::class, 'index']);

    Route::get('dean/dashboard', [DeanController::class, 'dashboard']); 
    Route::get('dean/programs', [DeanController::class, 'programs']);
    Route::get('dean/programs/{programId}/chair', [DeanController::class, 'getProgramChair']);
    Route::get('dean/documents', [DeanController::class, 'documents']);
    Route::get('dean/review-queue', [DeanController::class, 'reviewQueue']);
    Route::post('dean/notify-program-chair', [DeanController::class, 'notifyProgramChair']);

    // Task Notifications (Dean assigns tasks to Program Chairs with badges)
    Route::get('task-notifications/badge-count', [TaskNotificationController::class, 'getBadgeCount']);
    Route::get('task-notifications/pending', [TaskNotificationController::class, 'pending']);
    Route::post('task-notifications', [TaskNotificationController::class, 'store']); // Dean assigns task
    Route::post('task-notifications/{taskNotification}/mark-viewed', [TaskNotificationController::class, 'markAsViewed']);
    Route::post('task-notifications/{taskNotification}/mark-completed', [TaskNotificationController::class, 'markAsCompleted']);
    Route::post('task-notifications/{taskNotification}/dismiss', [TaskNotificationController::class, 'dismiss']);
    Route::apiResource('task-notifications', TaskNotificationController::class)->only(['index', 'show']);

    // Task Notification Files (Instruments & documents)
    Route::post('task-notifications/{taskNotification}/files', [TaskNotificationController::class, 'uploadFile']);
    Route::get('task-notifications/{taskNotification}/files', [TaskNotificationController::class, 'getFiles']);
    Route::get('task-notifications/{taskNotification}/files/{file}/download', [TaskNotificationController::class, 'downloadFile']);
    Route::post('task-notifications/{taskNotification}/files/{file}/forward', [TaskNotificationController::class, 'forwardFile']);

    Route::get('users', [UserController::class, 'index']);
    Route::get('program-chairs', [UserController::class, 'programChairs']);
    Route::get('area-in-charges', [UserController::class, 'areaInCharges']);
    Route::get('program-faculty', [UserController::class, 'programFaculty']);
    Route::get('users/search', [UserController::class, 'search']);

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

    // Join team using the 6-character team code
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
