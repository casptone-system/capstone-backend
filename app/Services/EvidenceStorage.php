<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceStorage
{
    public const SOURCE_DOCUMENT_VERSION = 'document_version';

    public const SOURCE_ROLE_STORAGE_FILE = 'role_storage_file';

    /**
     * After the remote evidence disk fails, skip it briefly so one dead host
     * cannot stall every later request on this process.
     */
    private static ?float $remoteDownUntil = null;

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

    public function store(string $directory, UploadedFile $file): string
    {
        return $this->storeUploaded(fn (string $disk) => $file->store($directory, $disk));
    }

    public function putFileAs(string $directory, UploadedFile $file, string $name): string
    {
        return $this->storeUploaded(fn (string $disk) => $file->storeAs($directory, $name, $disk));
    }

    public function put(string $path, string $contents): bool
    {
        $stored = $this->tryRemote(fn () => $this->disk()->put($path, $contents));

        if ($stored === true) {
            return true;
        }

        if ($this->diskName() === 'local') {
            return false;
        }

        return $this->localDisk()->put($path, $contents);
    }

    public function writeStream(string $path, $stream): bool
    {
        $stored = $this->tryRemote(fn () => $this->disk()->writeStream($path, $stream));

        if ($stored) {
            return true;
        }

        if ($this->diskName() === 'local') {
            return false;
        }

        return (bool) $this->localDisk()->writeStream($path, $stream);
    }

    public function exists(string $path): bool
    {
        return $this->locate($path) !== null;
    }

    public function getContents(string $path): ?string
    {
        $disk = $this->locate($path);

        if ($disk === null) {
            return null;
        }

        $contents = Storage::disk($disk)->get($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Prefer the configured evidence disk, then fall back to local during
     * a rolling migration so existing files remain downloadable.
     */
    public function locate(string $path): ?string
    {
        $onRemote = $this->tryRemote(fn () => $this->disk()->exists($path));

        if ($onRemote === true) {
            return $this->diskName();
        }

        if ($this->diskName() !== 'local' && $this->localDisk()->exists($path)) {
            return 'local';
        }

        if (Storage::disk('private')->exists($path)) {
            return 'private';
        }

        return null;
    }

    public function delete(string $path): bool
    {
        $deleted = false;

        $onRemote = $this->tryRemote(fn () => $this->disk()->exists($path) ? $this->disk()->delete($path) : false);

        if ($onRemote) {
            $deleted = true;
        }

        if ($this->diskName() !== 'local' && $this->localDisk()->exists($path)) {
            $deleted = $this->localDisk()->delete($path) || $deleted;
        }

        if (Storage::disk('private')->exists($path)) {
            $deleted = Storage::disk('private')->delete($path) || $deleted;
        }

        return $deleted;
    }

    public function deleteDirectory(string $path): bool
    {
        $deleted = false;

        $onRemote = $this->tryRemote(fn () => $this->disk()->deleteDirectory($path));

        if ($onRemote) {
            $deleted = true;
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
        $mimeType = $mimeType ?: 'application/octet-stream';

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.$this->safeFilename($name).'"',
            'Cache-Control' => 'private, no-store, no-transform',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (in_array($diskName, ['local', 'private'], true)) {
            $headers['Content-Length'] = (string) $disk->size($path);
        }

        return response()->stream(function () use ($disk, $path) {
            $stream = $disk->readStream($path);

            if ($stream === false) {
                return;
            }

            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, $headers);
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

    /**
     * @param  callable(string): string  $store
     */
    private function storeUploaded(callable $store): string
    {
        $stored = $this->tryRemote(fn () => $store($this->diskName()));

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        if ($this->diskName() === 'local') {
            throw new \RuntimeException('The file could not be stored.');
        }

        $fallback = $store('local');

        if (! is_string($fallback) || $fallback === '') {
            throw new \RuntimeException('The file could not be stored.');
        }

        return $fallback;
    }

    /**
     * @template T
     * @param  callable(): T  $operation
     * @return T|null
     */
    private function tryRemote(callable $operation): mixed
    {
        if ($this->remoteIsSkipped()) {
            return null;
        }

        try {
            $result = $operation();
            self::$remoteDownUntil = null;

            return $result;
        } catch (\Throwable $exception) {
            if ($this->diskName() === 'local') {
                throw $exception;
            }

            $this->rememberRemoteFailure($exception);

            return null;
        }
    }

    private function remoteIsSkipped(): bool
    {
        return $this->diskName() !== 'local'
            && self::$remoteDownUntil !== null
            && microtime(true) < self::$remoteDownUntil;
    }

    private function rememberRemoteFailure(\Throwable $exception): void
    {
        self::$remoteDownUntil = microtime(true) + 60;

        Log::warning('Evidence disk is unreachable. New files will be stored locally until it recovers.', [
            'disk' => $this->diskName(),
            'error' => $exception->getMessage(),
        ]);
    }
}
