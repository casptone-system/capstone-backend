<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SyncCursorService;
use Illuminate\Http\Request;

class SyncCursorController extends Controller
{
    public function show(Request $request, SyncCursorService $cursors)
    {
        $payload = $cursors->forUser($request->user());
        $etag = $cursors->etag($payload);

        $incoming = trim((string) $request->header('If-None-Match', ''));
        if ($incoming !== '' && $this->etagMatches($incoming, $etag)) {
            return response('', 304)->withHeaders([
                'ETag' => $etag,
                'Cache-Control' => 'private, no-cache',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Sync cursor retrieved.',
            'data' => $payload,
        ], 200)->withHeaders([
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    private function etagMatches(string $incoming, string $etag): bool
    {
        $candidates = array_map('trim', explode(',', $incoming));
        foreach ($candidates as $candidate) {
            if ($candidate === '*' || $candidate === $etag || $candidate === trim($etag, '"')) {
                return true;
            }
        }

        return false;
    }
}
