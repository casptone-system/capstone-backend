<?php

/**
 * Read-only Phase 1 profiler. Does not change application code.
 * Temporarily used to count queries and time SMTP vs assignment work.
 */

use App\Models\AccreditationArea;
use App\Models\User;
use App\Notifications\AreaInChargeAssignedNotification;
use App\Support\RoleSlug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$http = $app->make(Illuminate\Contracts\Http\Kernel::class);

function roleUser(string $role): ?User
{
    return User::query()
        ->whereHas('roles', fn ($q) => $q->where('name', $role))
        ->orderBy('id')
        ->first();
}

function hit(Illuminate\Contracts\Http\Kernel $http, User $user, string $method, string $uri, array $payload = []): array
{
    $token = $user->createToken('phase1-profile');
    $plain = $token->plainTextToken;

    DB::flushQueryLog();
    DB::enableQueryLog();

    $server = [
        'REQUEST_METHOD' => strtoupper($method),
        'REQUEST_URI' => $uri,
        'HTTP_AUTHORIZATION' => 'Bearer '.$plain,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ];
    $request = Request::create($uri, $method, $payload, [], [], $server, $payload ? json_encode($payload) : null);
    $request->headers->set('Authorization', 'Bearer '.$plain);
    $request->headers->set('Accept', 'application/json');

    $start = microtime(true);
    $response = $http->handle($request);
    $elapsedMs = round((microtime(true) - $start) * 1000, 1);

    $rawQueries = DB::getQueryLog();
    DB::disableQueryLog();
    DB::flushQueryLog();
    $queries = array_map(fn ($query) => [
        'sql' => $query['query'] ?? $query['sql'] ?? '',
        'time_ms' => $query['time'] ?? 0,
        'bindings_count' => count($query['bindings'] ?? []),
    ], $rawQueries);

    $content = $response->getContent();
    $decoded = json_decode($content, true);
    $bytes = strlen($content);

    PersonalAccessToken::findToken($plain)?->delete();

    $sqlCounts = [];
    foreach ($queries as $row) {
        $key = preg_replace('/\s+/', ' ', $row['sql']);
        $sqlCounts[$key] = ($sqlCounts[$key] ?? 0) + 1;
    }
    arsort($sqlCounts);
    $top = array_slice($sqlCounts, 0, 8, true);

    return [
        'status' => $response->getStatusCode(),
        'elapsed_ms' => $elapsedMs,
        'query_count' => count($queries),
        'query_time_ms' => round(array_sum(array_column($queries, 'time_ms')), 1),
        'payload_bytes' => $bytes,
        'payload_kb' => round($bytes / 1024, 1),
        'message' => is_array($decoded) ? ($decoded['message'] ?? null) : null,
        'top_sql' => $top,
        'slowest' => collect($queries)->sortByDesc('time_ms')->take(5)->map(fn ($q) => [
            'time_ms' => $q['time_ms'],
            'sql' => preg_replace('/\s+/', ' ', $q['sql']),
        ])->values()->all(),
    ];
}

$config = [
    'queue_connection' => config('queue.default'),
    'queue_driver' => config('queue.connections.'.config('queue.default').'.driver'),
    'cache_store' => config('cache.default'),
    'mail_mailer' => config('mail.default'),
    'mail_host' => config('mail.mailers.smtp.host'),
    'mail_port' => config('mail.mailers.smtp.port'),
    'app_env' => config('app.env'),
    'jobs_table_exists' => \Illuminate\Support\Facades\Schema::hasTable('jobs'),
    'cache_table_exists' => \Illuminate\Support\Facades\Schema::hasTable('cache'),
];

$tables = [
    'accreditation_cycles',
    'accreditation_areas',
    'programs',
    'documents',
    'reviews',
    'users',
    'parameter_content_rows',
    'parameter_row_statuses',
    'area_members',
    'tasks',
];
$indexes = [];
foreach ($tables as $table) {
    if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
        continue;
    }
    $rows = DB::select('SHOW INDEX FROM `'.$table.'`');
    $indexes[$table] = collect($rows)->map(fn ($r) => [
        'name' => $r->Key_name,
        'column' => $r->Column_name,
        'unique' => ((int) $r->Non_unique) === 0,
        'seq' => (int) $r->Seq_in_index,
    ])->all();
}

$counts = [
    'users' => User::count(),
    'programs' => \App\Models\Program::count(),
    'cycles' => \App\Models\AccreditationCycle::count(),
    'areas' => AccreditationArea::count(),
    'documents' => \App\Models\Document::count(),
    'reviews' => \App\Models\Review::count(),
    'tasks' => \App\Models\Task::count(),
];

$dean = roleUser(RoleSlug::DEAN) ?: roleUser('dean');
$chair = roleUser(RoleSlug::PROGRAM_CHAIR) ?: roleUser('program-chair');
$faculty = roleUser(RoleSlug::FACULTY) ?: roleUser('faculty');
$areaIncharge = roleUser(RoleSlug::AREA_IN_CHARGE) ?: roleUser('area-in-charge') ?: roleUser('area-incharge');

$endpoints = [];
if ($dean) {
    $endpoints['dean.dashboard'] = hit($http, $dean, 'GET', '/api/dean/dashboard');
    $endpoints['dean.level-status'] = hit($http, $dean, 'GET', '/api/accreditation-cycles/level-status?view=dean');
    $endpoints['dean.me'] = hit($http, $dean, 'GET', '/api/me');
}
if ($chair) {
    $endpoints['pc.program-chair-areas'] = hit($http, $chair, 'GET', '/api/program-chair/areas');
    $endpoints['pc.area-documents'] = hit($http, $chair, 'GET', '/api/program-chair/area-documents');
    $endpoints['pc.review-documents'] = hit($http, $chair, 'GET', '/api/program-chair/review-documents');
    $endpoints['pc.level-status'] = hit($http, $chair, 'GET', '/api/accreditation-cycles/level-status?view=program-chair');
    $endpoints['pc.workspaces'] = hit($http, $chair, 'GET', '/api/accreditation-workspaces');
    $endpoints['pc.program-faculty'] = hit($http, $chair, 'GET', '/api/program-faculty');
    $endpoints['pc.my-areas'] = hit($http, $chair, 'GET', '/api/users/me/areas');
    $endpoints['pc.dashboard'] = hit($http, $chair, 'GET', '/api/dashboard');
    $programId = method_exists($chair, 'assignedProgramId') ? $chair->assignedProgramId() : $chair->program_id;
    if ($programId) {
        $endpoints['pc.program-show'] = hit($http, $chair, 'GET', '/api/programs/'.$programId);
        $endpoints['pc.active-level'] = hit($http, $chair, 'GET', '/api/programs/'.$programId.'/active-level');
    }
}
if ($faculty) {
    $endpoints['faculty.my-areas'] = hit($http, $faculty, 'GET', '/api/users/me/areas');
    $endpoints['faculty.dashboard'] = hit($http, $faculty, 'GET', '/api/dashboard');
    $endpoints['faculty.level-status'] = hit($http, $faculty, 'GET', '/api/accreditation-cycles/level-status?view=faculty');
}
if ($areaIncharge) {
    $endpoints['aic.my-areas'] = hit($http, $areaIncharge, 'GET', '/api/users/me/areas');
    $endpoints['aic.level-status'] = hit($http, $areaIncharge, 'GET', '/api/accreditation-cycles/level-status?view=area-incharge');
}

$smtp = ['attempted' => false];
$mailer = config('mail.default');
if ($mailer === 'smtp' && config('mail.mailers.smtp.host')) {
    $smtp['attempted'] = true;
    $smtp['host'] = config('mail.mailers.smtp.host');
    $start = microtime(true);
    try {
        Mail::raw('ADAMS Phase 1 SMTP timing probe — please ignore.', function ($message) {
            $to = config('mail.from.address') ?: 'hello@example.com';
            $message->to($to)->subject('ADAMS Phase 1 SMTP probe');
        });
        $smtp['ok'] = true;
        $smtp['elapsed_ms'] = round((microtime(true) - $start) * 1000, 1);
        $smtp['error'] = null;
    } catch (Throwable $e) {
        $smtp['ok'] = false;
        $smtp['elapsed_ms'] = round((microtime(true) - $start) * 1000, 1);
        $smtp['error'] = $e->getMessage();
    }
} else {
    $smtp['skipped'] = 'MAIL_MAILER is '.$mailer.', not smtp';
}

$assign = ['attempted' => false];
if ($chair && method_exists($chair, 'assignedProgramId')) {
    $area = AccreditationArea::query()
        ->whereHas('cycle', function ($q) use ($chair) {
            $programId = $chair->assignedProgramId();
            if ($programId) {
                $q->where('program_id', $programId);
            }
        })
        ->whereNotNull('code')
        ->orderBy('id')
        ->first();
    $assignee = $area?->chair_id
        ? User::find($area->chair_id)
        : User::query()->whereHas('roles', fn ($q) => $q->where('name', RoleSlug::FACULTY))->first();

    if ($area && $assignee) {
        $assign['attempted'] = true;
        $assign['area_id'] = $area->id;
        $assign['assignee_id'] = $assignee->id;

        Notification::fake();
        $without = hit($http, $chair, 'POST', '/api/accreditation-areas/'.$area->id.'/assign-chair', [
            'chair_id' => $assignee->id,
            'confirm_reassign' => true,
        ]);
        $assign['without_mail'] = $without;
        $assign['notify_now_ms'] = $smtp['elapsed_ms'] ?? null;
        $assign['estimated_with_mail_ms'] = isset($smtp['elapsed_ms'])
            ? round(($without['elapsed_ms'] ?? 0) + $smtp['elapsed_ms'], 1)
            : null;
    }
}

$shouldQueue = [];
foreach (glob(app_path('Notifications/*.php')) as $file) {
    $src = file_get_contents($file);
    $shouldQueue[basename($file, '.php')] = [
        'uses_queueable' => str_contains($src, 'use Queueable'),
        'implements_should_queue' => str_contains($src, 'implements ShouldQueue'),
        'has_mail_channel' => str_contains($src, "'mail'"),
    ];
}

$explains = [];
$explainSql = [
    'areas_by_cycle' => 'EXPLAIN SELECT * FROM accreditation_areas WHERE cycle_id = 1 AND code IS NOT NULL ORDER BY id',
    'docs_by_program_status' => 'EXPLAIN SELECT * FROM documents WHERE program_id = 1 AND status IN ("Active","Draft") ORDER BY updated_at DESC',
    'reviews_by_cycle_status' => 'EXPLAIN SELECT * FROM reviews WHERE cycle_id = 1 AND current_status NOT IN ("Ready","Rejected")',
    'cycles_by_program_level' => 'EXPLAIN SELECT * FROM accreditation_cycles WHERE program_id = 1 AND level = "Level I"',
    'areas_by_chair' => 'EXPLAIN SELECT * FROM accreditation_areas WHERE chair_id = 1',
    'docs_by_content_row' => 'EXPLAIN SELECT content_row_id FROM documents WHERE content_row_id IS NOT NULL',
    'users_by_program' => 'EXPLAIN SELECT * FROM users WHERE program_id = 1',
];
foreach ($explainSql as $name => $sql) {
    try {
        $explains[$name] = json_decode(json_encode(DB::select($sql)), true);
    } catch (Throwable $e) {
        $explains[$name] = ['error' => $e->getMessage()];
    }
}

$out = [
    'config' => $config,
    'row_counts' => $counts,
    'users' => [
        'dean' => $dean?->only(['id', 'email']),
        'program_chair' => $chair?->only(['id', 'email']),
        'faculty' => $faculty?->only(['id', 'email']),
        'area_incharge' => $areaIncharge?->only(['id', 'email']),
        'user_has_chairedProgramId' => $chair ? method_exists($chair, 'chairedProgramId') : null,
        'user_has_assignedProgram' => $chair ? method_exists($chair, 'assignedProgram') : null,
    ],
    'endpoints' => $endpoints,
    'smtp' => $smtp,
    'assign_chair' => $assign,
    'notifications' => $shouldQueue,
    'indexes' => $indexes,
    'explains' => $explains,
];

$path = storage_path('app/phase1-profile.json');
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Wrote {$path}\n";
echo json_encode([
    'config' => $config,
    'row_counts' => $counts,
    'endpoint_summary' => collect($endpoints)->map(fn ($r) => [
        'status' => $r['status'],
        'ms' => $r['elapsed_ms'],
        'queries' => $r['query_count'],
        'kb' => $r['payload_kb'],
    ]),
    'smtp' => $smtp,
    'assign' => [
        'without_mail_ms' => $assign['without_mail']['elapsed_ms'] ?? null,
        'without_mail_queries' => $assign['without_mail']['query_count'] ?? null,
        'notify_now_ms' => $assign['notify_now_ms'] ?? null,
        'estimated_with_mail_ms' => $assign['estimated_with_mail_ms'] ?? null,
    ],
], JSON_PRETTY_PRINT);
