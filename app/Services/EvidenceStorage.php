<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceStorage
{
    public const SOURCE_DOCUMENT_VERSION = 'document_version';

    public const SOURCE_ROLE_STORAGE_FILE = 'role_storage_file';

    public function diskName(): string
    {
        return (string) config('filesystems.evidence_disk', 'local');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function localDisk(): Filesystem
    {
        return Storage::disk('local');
    }

    public function putFileAs(string $directory, UploadedFile $file, string $name): string
    {
        return $file->storeAs($directory, $name, $this->diskName());
    }

    public function writeStream(string $path, $stream): bool
    {
        return (bool) $this->disk()->writeStream($path, $stream);
    }

    public function exists(string $path): bool
    {
        return $this->locate($path) !== null;
    }

    /**
     * Prefer the configured evidence disk, then fall back to local during
     * a rolling migration so existing files remain downloadable.
     */
    public function locate(string $path): ?string
    {
        if ($this->disk()->exists($path)) {
            return $this->diskName();
        }

        if ($this->diskName() !== 'local' && $this->localDisk()->exists($path)) {
            return 'local';
        }

        return null;
    }

    public function delete(string $path): bool
    {
        $deleted = false;

        if ($this->disk()->exists($path)) {
            $deleted = $this->disk()->delete($path) || $deleted;
        }

        if ($this->diskName() !== 'local' && $this->localDisk()->exists($path)) {
            $deleted = $this->localDisk()->delete($path) || $deleted;
        }

        return $deleted;
    }

    public function deleteDirectory(string $path): bool
    {
        $deleted = false;

        if ($this->disk()->exists($path) || method_exists($this->disk(), 'deleteDirectory')) {
            $deleted = $this->disk()->deleteDirectory($path) || $deleted;
        }

        if ($this->diskName() !== 'local') {
            $deleted = $this->localDisk()->deleteDirectory($path) || $deleted;
        }

        return $deleted;
    }

    public function download(string $path, string $name, array $headers = [])
    {
        $disk = $this->locate($path);

        if ($disk === null) {
            return null;
        }

        return Storage::disk($disk)->download($path, $name, $headers);
    }

    public function streamInline(string $path, string $name, ?string $mimeType = null): ?StreamedResponse
    {
        $diskName = $this->locate($path);

        if ($diskName === null) {
            return null;
        }

        $disk = Storage::disk($diskName);
        $mimeType = $mimeType ?: ($disk->mimeType($path) ?: 'application/octet-stream');
        $size = $disk->size($path);

        return response()->stream(function () use ($disk, $path) {
            $stream = $disk->readStream($path);

            if ($stream === false) {
                return;
            }

            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.$this->safeFilename($name).'"',
            'Content-Length' => (string) $size,
            'Cache-Control' => 'private, no-store, no-transform',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function checksum(string $path, ?string $disk = null): string
    {
        $filesystem = Storage::disk($disk ?: $this->locate($path) ?: $this->diskName());
        $stream = $filesystem->readStream($path);

        if ($stream === false) {
            throw new \RuntimeException("Unable to read {$path} for checksum.");
        }

        $context = hash_init('sha256');

        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);

            if ($chunk === false) {
                break;
            }

            hash_update($context, $chunk);
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        return hash_final($context);
    }

    public function copyBetween(string $path, string $fromDisk, string $toDisk): bool
    {
        $source = Storage::disk($fromDisk);

        if (! $source->exists($path)) {
            return false;
        }

        $stream = $source->readStream($path);

        if ($stream === false) {
            return false;
        }

        $written = Storage::disk($toDisk)->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return (bool) $written;
    }

    public function documentUploadMaxKilobytes(): int
    {
        return (int) config('filesystems.document_upload_max_kb', 51200);
    }

    public function mediaUploadMaxKilobytes(): int
    {
        return (int) config('filesystems.media_upload_max_kb', 1048576);
    }

    public function maxUploadKilobytes(?string $mimeType = null, ?string $extension = null): int
    {
        if ($this->isMediaFile($mimeType, $extension)) {
            return $this->mediaUploadMaxKilobytes();
        }

        return $this->documentUploadMaxKilobytes();
    }

    public function chunkSizeBytes(): int
    {
        return (int) config('filesystems.chunk_size_bytes', 8 * 1024 * 1024);
    }

    public function chunkThresholdBytes(): int
    {
        return (int) config('filesystems.chunk_threshold_bytes', 50 * 1024 * 1024);
    }

    public function isMediaFile(?string $mimeType, ?string $extension = null): bool
    {
        $mime = strtolower((string) $mimeType);
        $ext = strtolower((string) $extension);

        return str_starts_with($mime, 'video/')
            || str_starts_with($mime, 'audio/')
            || in_array($ext, ['mp4', 'mov', 'webm', 'avi', 'mp3', 'wav', 'm4a'], true);
    }

    public function safeFilename(string $name): string
    {
        return str_replace(['"', "\r", "\n"], '', $name);
    }
}
