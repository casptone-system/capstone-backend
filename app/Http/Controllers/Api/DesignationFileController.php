<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DesignationFileResource;
use App\Models\DesignationFile;
use App\Services\EvidenceStorage;
use Illuminate\Http\Request;

class DesignationFileController extends Controller
{
    public function __construct(private readonly EvidenceStorage $storage) {}

    public function index(Request $request)
    {
        $files = DesignationFile::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return DesignationFileResource::collection($files)
            ->additional(['success' => true]);
    }

    public function download(Request $request, DesignationFile $designationFile)
    {
        abort_unless(
            (int) $designationFile->user_id === (int) $request->user()->id,
            403,
            'You can only download your own designation files.'
        );

        $contents = $this->storage->getContents((string) $designationFile->file_path);
        abort_if($contents === null, 404, 'This designation file is no longer available.');

        $filename = $designationFile->original_name ?: 'designation.pdf';

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Pragma' => 'public',
        ]);
    }
}

