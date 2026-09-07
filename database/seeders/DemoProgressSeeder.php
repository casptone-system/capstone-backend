<?php

namespace Database\Seeders;

use App\Models\AccreditationArea;
use App\Models\AreaMember;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\ParameterContentRow;
use App\Models\ParameterRowStatus;
use App\Models\Program;
use App\Models\ProgramMember;
use App\Models\User;
use App\Services\AreaProgressService;
use App\Services\EvidenceStorage;
use App\Support\ActiveCycle;
use App\Support\AreaParameterCatalog;
use App\Support\RoleSlug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class DemoProgressSeeder extends Seeder
{
    public const TITLE_PREFIX = 'Demo Evidence';

    public const FILE_PATH = 'demo/adams-demo-evidence.pdf';

    /**
     * Target approved-row completion per Level I area so the program lands ~50–60%.
     *
     * @var array<int, int>
     */
    private const AREA_TARGETS = [
        1 => 68,
        2 => 64,
        3 => 62,
        4 => 58,
        5 => 55,
        6 => 52,
        7 => 50,
        8 => 46,
        9 => 42,
        10 => 38,
    ];

    /**
     * Extra done+pending files per area so Program Chair has a real review queue.
     *
     * @var array<int, int>
     */
    private const AREA_PENDING = [
        1 => 6,
        2 => 5,
        3 => 5,
        4 => 5,
        5 => 4,
        6 => 4,
        7 => 4,
        8 => 3,
        9 => 3,
        10 => 3,
    ];

    /**
     * @var list<array{first: string, last: string, email: string}>
     */
    private const AREA_CHAIRS = [
        ['first' => 'Kara', 'last' => 'Bautista', 'email' => 'area@isu.edu.ph'],
        ['first' => 'Ramon', 'last' => 'Dela Cruz', 'email' => 'area2@isu.edu.ph'],
        ['first' => 'Isabel', 'last' => 'Mendoza', 'email' => 'area3@isu.edu.ph'],
        ['first' => 'Diego', 'last' => 'Navarro', 'email' => 'area4@isu.edu.ph'],
        ['first' => 'Camille', 'last' => 'Ocampo', 'email' => 'area5@isu.edu.ph'],
        ['first' => 'Rafael', 'last' => 'Gutierrez', 'email' => 'area6@isu.edu.ph'],
        ['first' => 'Andrea', 'last' => 'Lim', 'email' => 'area7@isu.edu.ph'],
        ['first' => 'Victor', 'last' => 'Pascual', 'email' => 'area8@isu.edu.ph'],
        ['first' => 'Joanna', 'last' => 'Mercado', 'email' => 'area9@isu.edu.ph'],
        ['first' => 'Luis', 'last' => 'Fernandez', 'email' => 'area10@isu.edu.ph'],
    ];

    /**
     * @var list<array{first: string, last: string, email: string}>
     */
    private const AREA_MEMBERS = [
        ['first' => 'Miguel', 'last' => 'Torres', 'email' => 'faculty@isu.edu.ph'],
        ['first' => 'Nina', 'last' => 'Aquino', 'email' => 'faculty2@isu.edu.ph'],
        ['first' => 'Carlo', 'last' => 'Reyes', 'email' => 'faculty3@isu.edu.ph'],
        ['first' => 'Bea', 'last' => 'Salvador', 'email' => 'faculty4@isu.edu.ph'],
        ['first' => 'Marco', 'last' => 'Tan', 'email' => 'faculty5@isu.edu.ph'],
        ['first' => 'Pia', 'last' => 'Villar', 'email' => 'faculty6@isu.edu.ph'],
        ['first' => 'Ethan', 'last' => 'Ramos', 'email' => 'faculty7@isu.edu.ph'],
        ['first' => 'Sofia', 'last' => 'Chua', 'email' => 'faculty8@isu.edu.ph'],
        ['first' => 'Gabriel', 'last' => 'Sy', 'email' => 'faculty9@isu.edu.ph'],
        ['first' => 'Lara', 'last' => 'Flores', 'email' => 'faculty10@isu.edu.ph'],
    ];

    public function run(EvidenceStorage $storage, AreaProgressService $progress): void
    {
        $program = Program::query()->where('code', OrgStructureSeeder::BSFAS_CODE)->first();

        if (! $program) {
            $this->command?->warn('DemoProgressSeeder skipped: BSFAS program is missing.');

            return;
        }

        $cycle = ActiveCycle::forProgram($program);

        if (! $cycle) {
            $this->command?->warn('DemoProgressSeeder skipped: no active accreditation cycle.');

            return;
        }

        $areas = AccreditationArea::query()
            ->where('cycle_id', $cycle->id)
            ->whereNotNull('code')
            ->orderBy('id')
            ->get()
            ->sortBy(function (AccreditationArea $area) {
                preg_match('/(\d+)/', (string) $area->code, $match);

                return (int) ($match[1] ?? $area->id);
            })
            ->values();

        if ($areas->isEmpty()) {
            $this->command?->warn('DemoProgressSeeder skipped: no AACCUP areas on the active cycle.');

            return;
        }

        $this->storeSharedPdf($storage);
        $chairs = $this->ensureUsers($program, self::AREA_CHAIRS, RoleSlug::AREA_IN_CHARGE);
        $members = $this->ensureUsers($program, self::AREA_MEMBERS, RoleSlug::FACULTY);
        $this->clearPreviousDemoEvidence($program);

        foreach ($areas as $index => $area) {
            $slot = $index + 1;
            $chair = $chairs[$index] ?? $chairs[0];
            $member = $members[$index] ?? $members[0];
            $partner = $members[($index + 3) % count($members)];

            $area->update(['chair_id' => $chair->id]);
            $this->attachMember($area, $member);
            $this->attachMember($area, $partner);

            AreaParameterCatalog::ensureSeeded($area);
            $this->seedAreaEvidence($program, $area, $chair, $member, $slot);
            $percent = $progress->refresh($area);
            $this->command?->info("Seeded {$area->sidebarLabel()} at {$percent}% with chair {$chair->email}.");
        }

        $programRate = $progress->refreshAndSyncProgram($program->fresh() ?? $program);
        $this->command?->info("BSFAS program completion is now {$programRate}%.");
    }

    /**
     * @param  list<array{first: string, last: string, email: string}>  $accounts
     * @return list<User>
     */
    private function ensureUsers(Program $program, array $accounts, string $role): array
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);

        $users = [];

        foreach ($accounts as $account) {
            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['first'].' '.$account['last'],
                    'first_name' => $account['first'],
                    'middle_name' => null,
                    'last_name' => $account['last'],
                    'password' => DemoUsersSeeder::PASSWORD,
                    'email_verified_at' => now(),
                    'college_id' => $program->college_id,
                    'program_id' => $program->id,
                ]
            );

            $user->syncRoles([$role]);
            ProgramMember::query()->updateOrCreate(
                [
                    'program_id' => $program->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $role,
                    'joined_at' => now(),
                ]
            );
            $users[] = $user;
        }

        return $users;
    }

    private function attachMember(AccreditationArea $area, User $user): void
    {
        AreaMember::query()->updateOrCreate(
            [
                'area_id' => $area->id,
                'user_id' => $user->id,
            ],
            ['role' => 'member']
        );
    }

    private function seedAreaEvidence(Program $program, AccreditationArea $area, User $chair, User $member, int $slot): void
    {
        $rows = ParameterContentRow::query()
            ->whereHas('parameter', fn ($query) => $query->where('area_id', $area->id))
            ->orderBy('id')
            ->get()
            ->reject(fn (ParameterContentRow $row) => $row->isSectionHeading())
            ->values();

        $total = $rows->count();

        if ($total === 0) {
            return;
        }

        $approvedCount = (int) round($total * ((self::AREA_TARGETS[$slot] ?? 52) / 100));
        $pendingCount = min(self::AREA_PENDING[$slot] ?? 4, max(0, $total - $approvedCount));
        $now = now();
        $documents = [];
        $statuses = [];

        foreach ($rows as $index => $row) {
            $kind = $index < $approvedCount
                ? 'Approved'
                : ($index < $approvedCount + $pendingCount ? 'Active' : null);

            if ($kind === null) {
                continue;
            }

            $uploader = $index % 2 === 0 ? $member : $chair;
            $versionCount = ($kind === 'Approved' && $index % 5 === 0) ? 2 : 1;
            $documents[] = [
                'row' => $row,
                'status' => $kind,
                'uploader' => $uploader,
                'versions' => $versionCount,
            ];
            $statuses[] = [
                'content_row_id' => $row->id,
                'is_done' => true,
                'done_by' => $uploader->id,
                'done_at' => $now->copy()->subDays(max(1, 20 - $index % 12)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($statuses, 80) as $chunk) {
            ParameterRowStatus::query()->upsert(
                $chunk,
                ['content_row_id'],
                ['is_done', 'done_by', 'done_at', 'updated_at']
            );
        }

        $now = now();
        $documentRows = [];

        foreach ($documents as $item) {
            $documentRows[] = [
                'program_id' => $program->id,
                'area_id' => $area->id,
                'task_id' => null,
                'content_row_id' => $item['row']->id,
                'title' => self::TITLE_PREFIX.' — '.$area->sidebarLabel().' — row '.$item['row']->id,
                'description' => 'Seeded AACCUP evidence for realistic program completion.',
                'school_year' => '2025-2026',
                'uploaded_by' => $item['uploader']->id,
                'current_version' => $item['versions'],
                'status' => $item['status'],
                'created_at' => $now->copy()->subDays(8),
                'updated_at' => $now->copy()->subDays($item['status'] === 'Active' ? 1 : 3),
            ];
        }

        foreach (array_chunk($documentRows, 80) as $chunk) {
            DB::table('documents')->insert($chunk);
        }

        $saved = Document::query()
            ->where('program_id', $program->id)
            ->where('area_id', $area->id)
            ->where('title', 'like', self::TITLE_PREFIX.'%')
            ->get()
            ->keyBy('content_row_id');

        $versions = [];

        foreach ($documents as $item) {
            $document = $saved->get($item['row']->id);
            if (! $document) {
                continue;
            }

            for ($version = 1; $version <= $item['versions']; $version++) {
                $versions[] = [
                    'document_id' => $document->id,
                    'version' => $version,
                    'file_path' => self::FILE_PATH,
                    'original_name' => $version === 1
                        ? 'area-'.$slot.'-evidence-v1.pdf'
                        : 'area-'.$slot.'-evidence-v'.$version.'-revised.pdf',
                    'mime_type' => 'application/pdf',
                    'file_size' => 1480,
                    'uploaded_by' => $item['uploader']->id,
                    'created_at' => $now->copy()->subDays(9 - $version),
                    'updated_at' => $now->copy()->subDays(9 - $version),
                ];
            }
        }

        foreach (array_chunk($versions, 80) as $chunk) {
            DB::table('document_versions')->insert($chunk);
        }
    }

    private function clearPreviousDemoEvidence(Program $program): void
    {
        $oldIds = Document::query()
            ->where('program_id', $program->id)
            ->where('title', 'like', self::TITLE_PREFIX.'%')
            ->pluck('id');

        if ($oldIds->isEmpty()) {
            return;
        }

        DocumentVersion::query()->whereIn('document_id', $oldIds)->delete();
        Document::query()->whereIn('id', $oldIds)->delete();
    }

    private function storeSharedPdf(EvidenceStorage $storage): void
    {
        if ($storage->exists(self::FILE_PATH)) {
            return;
        }

        $storage->put(self::FILE_PATH, $this->demoPdf());
    }

    private function demoPdf(): string
    {
        return "%PDF-1.4\n".
            "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n".
            "2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n".
            "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n".
            "4 0 obj<</Length 68>>stream\n".
            "BT /F1 16 Tf 72 720 Td (ADAMS demo accreditation evidence) Tj ET\n".
            "endstream\nendobj\n".
            "5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n".
            "trailer<</Root 1 0 R>>\n%%EOF\n";
    }
}
