<?php

namespace Tests\Feature;

use App\Services\EvidenceStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class Phase5EvidencePresignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.evidence_disk' => 'local']);
        Storage::fake('local');
    }

    public function test_temporary_url_is_null_on_local_disk(): void
    {
        Storage::disk('local')->put('evidence/demo.pdf', '%PDF-1.4 demo');

        $url = app(EvidenceStorage::class)->temporaryUrl(
            'evidence/demo.pdf',
            'inline',
            'application/pdf',
            'demo.pdf'
        );

        $this->assertNull($url);
    }

    public function test_presign_request_falls_back_to_stream_on_local_disk(): void
    {
        Storage::disk('local')->put('evidence/demo.pdf', '%PDF-1.4 demo');

        $request = Request::create('/api/documents/1/preview', 'GET', ['presign' => 1]);
        $response = app(EvidenceStorage::class)->respond(
            $request,
            'evidence/demo.pdf',
            'demo.pdf',
            'inline',
            'application/pdf'
        );

        $this->assertInstanceOf(StreamedResponse::class, $response);
    }

    public function test_missing_path_returns_null(): void
    {
        $request = Request::create('/api/documents/1/preview', 'GET', ['presign' => 1]);
        $response = app(EvidenceStorage::class)->respond(
            $request,
            'evidence/missing.pdf',
            'missing.pdf',
            'inline',
            'application/pdf'
        );

        $this->assertNull($response);
    }
}
