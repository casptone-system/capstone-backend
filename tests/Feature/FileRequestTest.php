<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Document;
use App\Models\FileRequest;
use App\Models\Program;
use App\Models\User;
use App\Notifications\FileRequestCreatedNotification;
use App\Notifications\FileRequestDeclinedNotification;
use App\Notifications\FileRequestFulfilledNotification;
use App\Notifications\FileRequestNeededByNotification;
use App\Notifications\FileRequestReopenedNotification;
use App\Services\FileRequestReminderService;
use App\Support\FileRequestRules;
use App\Support\RoleSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FileRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_program_chair_can_request_a_file_from_another_programs_current_chair(): void
    {
        [$fromProgram, $requester] = $this->programWithChair('BS Information Technology');
        [$toProgram, $recipient] = $this->programWithChair('BS Business Administration');

        $response = $this->act($requester)->postJson('/api/file-requests', [
            'recipient_program_id' => $toProgram->id,
            'title' => 'Institution calendar',
            'description' => 'Shared across programs.',
            'needed_by' => '2026-12-01',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Institution calendar');
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.fromProgram.id', $fromProgram->id);
        $response->assertJsonPath('data.toProgram.id', $toProgram->id);
        $response->assertJsonPath('data.toProgram.chairName', $recipient->name);
        $this->assertStringNotContainsString($recipient->email, $response->getContent());

        $this->assertDatabaseHas('file_requests', [
            'requester_program_id' => $fromProgram->id,
            'requester_user_id' => $requester->id,
            'recipient_program_id' => $toProgram->id,
            'recipient_user_id' => $recipient->id,
            'status' => 'pending',
        ]);

        Notification::assertSentTo($recipient, FileRequestCreatedNotification::class, function (FileRequestCreatedNotification $notification, array $channels) {
            return in_array('mail', $channels, true) && in_array('database', $channels, true);
        });
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'file_request_created',
            'user_id' => $requester->id,
            'status' => 'success',
        ]);
    }

    public function test_request_follows_the_current_chair_when_the_chair_changes(): void
    {
        [$fromProgram, $requester] = $this->programWithChair();
        [$toProgram, $originalChair] = $this->programWithChair();
        $id = $this->createRequest($requester, $toProgram, 'Handover file');

        $newChair = $this->userWithRole(RoleSlug::PROGRAM_CHAIR);
        $toProgram->update(['chair_id' => $newChair->id]);
        $newChair->update(['program_id' => $toProgram->id, 'college_id' => $toProgram->college_id]);

        $this->act($originalChair)->post("/api/file-requests/{$id}/fulfill", [
            'files' => [$this->pdf()],
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->act($newChair)->getJson('/api/file-requests?box=incoming')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.canFulfill', true);

        $this->act($newChair)->post("/api/file-requests/{$id}/fulfill", [
            'files' => [$this->pdf('handover.pdf')],
        ], ['Accept' => 'application/json'])->assertOk();

        Notification::assertSentTo($requester, FileRequestFulfilledNotification::class);
        $this->act($originalChair)->getJson('/api/file-requests?box=incoming')->assertForbidden();
    }

    public function test_only_a_current_program_chair_can_create_and_chairless_programs_cannot_receive(): void
    {
        [$fromProgram, $requester, $college] = $this->programWithChair();
        $chairless = Program::factory()->create([
            'college_id' => College::factory()->create(['name' => 'College of Arts'])->id,
            'name' => 'BS Biology',
            'code' => 'BSB',
            'chair_id' => null,
        ]);
        $unassigned = $this->userWithRole(RoleSlug::PROGRAM_CHAIR);
        $unassigned->update(['program_id' => $fromProgram->id, 'college_id' => $college->id]);

        $lookup = $this->act($requester)->getJson('/api/file-requests/programs');
        $lookup->assertOk();
        $this->assertStringNotContainsString($requester->email, $lookup->getContent());
        $this->assertStringNotContainsString('"id":'.$fromProgram->id, $lookup->getContent());
        $lookup->assertJsonFragment([
            'id' => $chairless->id,
            'name' => 'BS Biology',
            'canReceive' => false,
            'disabledReason' => 'No chair assigned',
            'chairName' => null,
        ]);

        $this->act($requester)->postJson('/api/file-requests', [
            'recipient_program_id' => $chairless->id,
            'title' => 'Missing chair',
        ])->assertStatus(422);

        $this->act($requester)->postJson('/api/file-requests', [
            'recipient_program_id' => $fromProgram->id,
            'title' => 'Myself',
        ])->assertStatus(422);

        $this->act($unassigned)->getJson('/api/file-requests/programs')->assertForbidden();
        $this->act($unassigned)->postJson('/api/file-requests', [
            'recipient_program_id' => $chairless->id,
            'title' => 'Nope',
        ])->assertForbidden();

        $this->assertSame(0, FileRequest::count());
    }

    public function test_open_duplicate_titles_are_blocked_and_daily_creation_is_capped(): void
    {
        [, $requester] = $this->programWithChair();
        [$toProgram] = $this->programWithChair();

        $this->createRequest($requester, $toProgram, 'Shared calendar');
        $this->act($requester)->postJson('/api/file-requests', [
            'recipient_program_id' => $toProgram->id,
            'title' => '  shared   calendar ',
        ])->assertStatus(422);

        $id = FileRequest::query()->value('id');
        $this->act($requester)->postJson("/api/file-requests/{$id}/cancel")->assertOk();
        $this->act($requester)->postJson('/api/file-requests', [
            'recipient_program_id' => $toProgram->id,
            'title' => 'Shared calendar',
        ])->assertCreated();

        FileRequest::query()->delete();
        for ($i = 0; $i < FileRequestRules::DAILY_LIMIT; $i++) {
            $this->createRequest($requester, $toProgram, 'Request '.$i);
        }

        $this->act($requester)->postJson('/api/file-requests', [
            'recipient_program_id' => $toProgram->id,
            'title' => 'One too many',
        ])->assertStatus(422);
        $this->assertSame(FileRequestRules::DAILY_LIMIT, FileRequest::count());
    }

    public function test_recipient_can_fulfill_or_decline_and_files_do_not_affect_documents_or_area_progress(): void
    {
        [$fromProgram, $requester] = $this->programWithChair();
        [$toProgram, $recipient] = $this->programWithChair();
        $area = AccreditationArea::factory()->create([
            'cycle_id' => AccreditationCycle::factory()->create([
                'program_id' => $toProgram->id,
                'college_id' => $toProgram->college_id,
            ])->id,
            'progress_percent' => 41,
        ]);
        $foreignDocument = Document::factory()->create([
            'program_id' => $toProgram->id,
            'uploaded_by' => $recipient->id,
            'area_id' => null,
            'content_row_id' => null,
            'title' => 'Private program file',
        ]);

        $evidenceBefore = $this->act($recipient)->getJson('/api/dashboard')->json('data.summary.totalEvidence');
        $id = $this->createRequest($requester, $toProgram, 'Shared policy');

        $this->act($recipient)->post("/api/file-requests/{$id}/fulfill", [
            'files' => [
                UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        config(['filesystems.document_upload_max_kb' => 1]);
        $this->act($recipient)->post("/api/file-requests/{$id}/fulfill", [
            'files' => [$this->pdf('big.pdf', 3)],
        ], ['Accept' => 'application/json'])->assertStatus(422);
        config(['filesystems.document_upload_max_kb' => 51200]);

        $this->assertSame('pending', FileRequest::find($id)->status);
        $this->assertSame(0, FileRequest::find($id)->files()->count());

        $tooMany = [];
        for ($i = 0; $i < FileRequestRules::MAX_FILES + 1; $i++) {
            $tooMany[] = $this->pdf('extra-'.$i.'.pdf');
        }
        $this->act($recipient)->post("/api/file-requests/{$id}/fulfill", [
            'files' => $tooMany,
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, FileRequest::find($id)->files()->count());

        $fulfilled = $this->act($recipient)->post("/api/file-requests/{$id}/fulfill", [
            'files' => [$this->pdf('policy.pdf'), $this->pdf('annex.pdf')],
        ], ['Accept' => 'application/json']);

        $fulfilled->assertOk();
        $fulfilled->assertJsonPath('data.status', 'fulfilled');
        $fulfilled->assertJsonMissingPath('data.files.0.filePath');
        $this->assertCount(2, $fulfilled->json('data.files'));
        $this->assertSame(1, Document::count());
        $this->assertSame(41, $area->fresh()->progress_percent);
        $this->assertSame($evidenceBefore, $this->act($recipient)->getJson('/api/dashboard')->json('data.summary.totalEvidence'));

        $this->act($requester)->getJson('/api/documents/'.$foreignDocument->id)->assertForbidden();
        $this->act($requester)->getJson('/api/documents/'.$foreignDocument->id.'/download')->assertForbidden();
        $this->act($requester)->getJson('/api/documents/'.$foreignDocument->id.'/preview')->assertForbidden();
        $this->act($requester)->getJson('/api/documents?program_id='.$toProgram->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $fileId = $fulfilled->json('data.files.0.id');
        $this->act($requester)->get('/api/file-requests/'.$id.'/files/'.$fileId.'/download')->assertOk();
        $this->act($requester)->get('/api/file-requests/'.$id.'/files/'.$fileId.'/preview')->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'file_request_downloaded',
            'user_id' => $requester->id,
            'status' => 'success',
        ]);
        Notification::assertSentTo($requester, FileRequestFulfilledNotification::class);

        $otherRequester = $this->programWithChair()[1];
        $otherId = $this->createRequest($otherRequester, $toProgram, 'Different request');
        $otherFile = $this->act($recipient)->post("/api/file-requests/{$otherId}/fulfill", [
            'files' => [$this->pdf('other.pdf')],
        ], ['Accept' => 'application/json'])->json('data.files.0.id');

        $this->act($requester)->get('/api/file-requests/'.$otherId.'/files/'.$otherFile.'/download')->assertForbidden();
        $this->act($requester)->get('/api/file-requests/'.$otherId.'/files/'.$otherFile.'/preview')->assertForbidden();
        $this->act($requester)->get('/api/file-requests/'.$id.'/files/'.$otherFile.'/download')->assertNotFound();
        $this->act($requester)->get('/api/file-requests/'.$id.'/files/'.$otherFile.'/preview')->assertNotFound();
    }

    public function test_decline_cancel_and_file_removal_follow_the_current_chair(): void
    {
        [, $requester] = $this->programWithChair();
        [$toProgram, $recipient] = $this->programWithChair();
        $pending = $this->createRequest($requester, $toProgram, 'Maybe later');

        $this->act($recipient)->postJson("/api/file-requests/{$pending}/cancel")->assertForbidden();
        $this->act($requester)->postJson("/api/file-requests/{$pending}/decline", [
            'decline_reason' => 'No',
        ])->assertForbidden();

        $this->act($recipient)->postJson("/api/file-requests/{$pending}/decline", [
            'decline_reason' => 'We do not have this file.',
        ])->assertOk()->assertJsonPath('data.status', 'declined');
        Notification::assertSentTo($requester, FileRequestDeclinedNotification::class);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'file_request_declined',
            'user_id' => $recipient->id,
            'status' => 'success',
        ]);

        $removable = $this->createRequest($requester, $toProgram, 'Two files');
        $files = $this->act($recipient)->post("/api/file-requests/{$removable}/fulfill", [
            'files' => [$this->pdf('one.pdf'), $this->pdf('two.pdf')],
        ], ['Accept' => 'application/json'])->json('data.files');

        $this->act($requester)->deleteJson('/api/file-requests/'.$removable.'/files/'.$files[0]['id'])->assertForbidden();
        $this->act($recipient)->deleteJson('/api/file-requests/'.$removable.'/files/'.$files[0]['id'])
            ->assertOk()
            ->assertJsonPath('data.status', 'fulfilled');
        $this->act($recipient)->deleteJson('/api/file-requests/'.$removable.'/files/'.$files[1]['id'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'file_request_file_removed',
            'user_id' => $recipient->id,
            'status' => 'success',
        ]);
        Notification::assertSentTo($requester, FileRequestReopenedNotification::class);
        $this->act($requester)->get('/api/file-requests/'.$removable.'/files/'.$files[1]['id'].'/download')->assertNotFound();

        $cancelled = $this->createRequest($requester, $toProgram, 'Cancel me');
        $this->act($requester)->postJson("/api/file-requests/{$cancelled}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'file_request_cancelled',
            'user_id' => $requester->id,
            'status' => 'success',
        ]);
    }

    public function test_oversight_roles_can_read_requests_and_deans_cannot(): void
    {
        [, $requester] = $this->programWithChair();
        [$toProgram, $recipient, $college] = $this->programWithChair();
        $id = $this->createRequest($requester, $toProgram, 'Oversight copy');
        $fileId = $this->act($recipient)->post("/api/file-requests/{$id}/fulfill", [
            'files' => [$this->pdf('oversight.pdf')],
        ], ['Accept' => 'application/json'])->json('data.files.0.id');

        foreach ([RoleSlug::QA, RoleSlug::VPAA, RoleSlug::SUPERADMIN] as $role) {
            $user = $this->userWithRole($role);
            $this->act($user)->getJson('/api/file-requests')->assertOk()->assertJsonPath('data.0.id', $id);
            $this->act($user)->get('/api/file-requests/'.$id.'/files/'.$fileId.'/download')->assertOk();
            $this->act($user)->postJson('/api/file-requests', [
                'recipient_program_id' => $toProgram->id,
                'title' => 'Not allowed',
            ])->assertForbidden();
            $this->act($user)->post("/api/file-requests/{$id}/fulfill", [
                'files' => [$this->pdf()],
            ], ['Accept' => 'application/json'])->assertForbidden();
        }

        $dean = $this->userWithRole(RoleSlug::DEAN);
        $dean->update(['college_id' => $college->id]);
        $this->act($dean)->getJson('/api/file-requests')->assertForbidden();
        $this->act($dean)->getJson('/api/file-requests/programs')->assertForbidden();
        $this->act($dean)->get('/api/file-requests/'.$id.'/files/'.$fileId.'/download')->assertForbidden();
        $this->act($this->userWithRole(RoleSlug::ACCREDITOR))->getJson('/api/file-requests')->assertForbidden();
        $this->act($this->userWithRole(RoleSlug::FACULTY))->getJson('/api/file-requests')->assertForbidden();
    }

    public function test_program_lookup_is_rate_limited_to_chairs(): void
    {
        [, $requester] = $this->programWithChair();

        for ($i = 0; $i < 30; $i++) {
            $this->act($requester)->getJson('/api/file-requests/programs')->assertOk();
        }

        $this->act($requester)->getJson('/api/file-requests/programs')->assertStatus(429);
    }

    public function test_needed_by_reminder_is_sent_once_to_the_current_recipient_chair(): void
    {
        [, $requester] = $this->programWithChair();
        [, $recipient] = $this->programWithChair();
        $toProgram = Program::query()->where('chair_id', $recipient->id)->firstOrFail();
        $due = $this->createRequest($requester, $toProgram, 'Due today', now('Asia/Manila')->toDateString());
        $later = $this->createRequest($requester, $toProgram, 'Due later', now('Asia/Manila')->addDay()->toDateString());

        $sent = app(FileRequestReminderService::class)->sendDueReminders();
        $this->assertSame(1, $sent);
        Notification::assertSentToTimes($recipient, FileRequestNeededByNotification::class, 1);
        $this->assertNotNull(FileRequest::find($due)->needed_by_reminded_at);
        $this->assertNull(FileRequest::find($later)->needed_by_reminded_at);

        $this->assertSame(0, app(FileRequestReminderService::class)->sendDueReminders());
        Notification::assertSentToTimes($recipient, FileRequestNeededByNotification::class, 1);
    }

    public function test_request_lists_do_not_add_queries_as_rows_grow(): void
    {
        [, $requester] = $this->programWithChair();
        [$toProgram, $recipient] = $this->programWithChair();
        $this->createRequest($requester, $toProgram, 'Only one');
        $this->act($recipient)->getJson('/api/file-requests?box=incoming')->assertOk();

        $one = $this->queryCount(fn () => $this->act($recipient)->getJson('/api/file-requests?box=incoming')->assertOk());

        for ($i = 0; $i < 4; $i++) {
            $this->createRequest($requester, $toProgram, 'Extra '.$i);
        }

        $five = $this->queryCount(fn () => $this->act($recipient)->getJson('/api/file-requests?box=incoming')->assertOk()->assertJsonCount(5, 'data'));

        $this->assertSame($one, $five);
    }

    private function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function createRequest(User $requester, Program $recipient, string $title, ?string $neededBy = null): int
    {
        $payload = [
            'recipient_program_id' => $recipient->id,
            'title' => $title,
        ];
        if ($neededBy) {
            $payload['needed_by'] = $neededBy;
        }

        return (int) $this->act($requester)->postJson('/api/file-requests', $payload)
            ->assertCreated()
            ->json('data.id');
    }

    private function act(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    /**
     * @return array{0: Program, 1: User, 2: College}
     */
    private function programWithChair(?string $name = null): array
    {
        $college = College::factory()->create();
        $chair = $this->userWithRole(RoleSlug::PROGRAM_CHAIR);
        $program = Program::factory()->create([
            'college_id' => $college->id,
            'name' => $name ?: 'Bachelor of '.fake()->unique()->word(),
            'chair_id' => $chair->id,
        ]);
        $chair->update(['program_id' => $program->id, 'college_id' => $college->id]);

        return [$program, $chair, $college];
    }

    private function userWithRole(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function pdf(string $name = 'request.pdf', int $kilobytes = 12): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }
}
