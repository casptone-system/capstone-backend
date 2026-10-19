<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Program;
use App\Models\User;
use App\Support\RoleSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Phase6LoadBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_light_load_fifty_users_typical_reads_report_percentiles(): void
    {
        Notification::fake();

        Role::findOrCreate(RoleSlug::PROGRAM_CHAIR, 'web');
        Role::findOrCreate(RoleSlug::FACULTY, 'web');

        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);
        AccreditationArea::factory()->count(3)->create(['cycle_id' => $cycle->id]);

        $chair = User::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
            'email_verified_at' => now(),
        ]);
        $chair->assignRole(RoleSlug::PROGRAM_CHAIR);
        $program->update(['chair_id' => $chair->id]);

        $users = [];
        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create([
                'program_id' => $program->id,
                'college_id' => $college->id,
                'email_verified_at' => now(),
            ]);
            $user->assignRole(RoleSlug::FACULTY);
            $users[] = $user;
        }

        $samples = [
            'GET /api/me' => [],
            'GET /api/dashboard' => [],
            'GET /api/users/me/areas' => [],
            'GET /api/sync/cursor' => [],
            'GET /api/program-chair/areas' => [],
        ];
        $errors = 0;
        $total = 0;
        $rounds = 2;

        $wallStart = hrtime(true);

        for ($round = 0; $round < $rounds; $round++) {
            foreach ($users as $index => $user) {
                $actor = ($index % 10 === 0) ? $chair : $user;
                Sanctum::actingAs($actor);

                $paths = [
                    'GET /api/me' => '/api/me',
                    'GET /api/dashboard' => '/api/dashboard',
                    'GET /api/users/me/areas' => '/api/users/me/areas',
                    'GET /api/sync/cursor' => '/api/sync/cursor',
                ];
                if ($actor->isProgramChair()) {
                    $paths['GET /api/program-chair/areas'] = '/api/program-chair/areas';
                }

                foreach ($paths as $label => $path) {
                    $total++;
                    $started = hrtime(true);
                    $response = $this->getJson($path);
                    $ms = (hrtime(true) - $started) / 1e6;
                    $samples[$label][] = $ms;
                    if ($response->status() >= 400) {
                        $errors++;
                    }
                }
            }
        }

        $wallMs = (hrtime(true) - $wallStart) / 1e6;

        $endpoints = [];
        foreach ($samples as $label => $times) {
            if ($times === []) {
                continue;
            }
            sort($times);
            $endpoints[$label] = [
                'n' => count($times),
                'p50_ms' => $this->percentile($times, 50),
                'p95_ms' => $this->percentile($times, 95),
                'max_ms' => round(max($times), 2),
            ];
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'mode' => 'sequential_http_kernel_via_phpunit',
            'note' => 'Simulates 50 users × 2 rounds of typical authenticated reads. Not OS-level concurrency; measures app+DB path latency under repeated load.',
            'users' => 50,
            'rounds' => $rounds,
            'total_requests' => $total,
            'errors' => $errors,
            'wall_ms' => round($wallMs, 2),
            'throughput_rps' => $wallMs > 0 ? round($total / ($wallMs / 1000), 2) : null,
            'budgets' => [
                'dashboard_p95_ms' => 300,
                'assignment_ms' => 500,
            ],
            'endpoints' => $endpoints,
        ];

        $path = storage_path('app/phase6-load.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT));

        $this->assertSame(0, $errors, 'Load benchmark had HTTP errors; see storage/app/phase6-load.json');
        $this->assertArrayHasKey('GET /api/sync/cursor', $endpoints);
        $this->assertGreaterThan(0, $endpoints['GET /api/me']['n']);

        // Soft budget check — record in JSON even if local sqlite is slower than prod.
        fwrite(STDOUT, "\nPhase 6 load p95 (ms): ".json_encode(
            array_map(fn ($row) => $row['p95_ms'], $endpoints)
        )."\n");
    }

    /**
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $p): float
    {
        $idx = (int) ceil(($p / 100) * count($sorted)) - 1;
        $idx = max(0, min(count($sorted) - 1, $idx));

        return round($sorted[$idx], 2);
    }
}
