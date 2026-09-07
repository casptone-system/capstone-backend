<?php

namespace App\Filesystem;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;

class SupabaseStorageAdapter implements FilesystemAdapter
{
    public function __construct(
        private readonly string $projectUrl,
        private readonly string $serviceRoleKey,
        private readonly string $bucket,
    ) {}

    public function fileExists(string $path): bool
    {
        try {
            $response = $this->client()->head($this->objectUrl($path));

            return $response->successful();
        } catch (\Throwable $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            return $this->listContents($path, false)->valid();
        } catch (\Throwable $e) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $e);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $response = $this->client()
            ->withHeaders(['x-upsert' => 'true'])
            ->withBody($contents, $this->contentType($path, $config))
            ->post($this->objectUrl($path));

        if (! $response->successful()) {
            throw UnableToWriteFile::atLocation($path, $response->body());
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $body = stream_get_contents($contents);

        if ($body === false) {
            throw UnableToWriteFile::atLocation($path, 'Unable to read the upload stream.');
        }

        $this->write($path, $body, $config);
    }

    public function read(string $path): string
    {
        $response = $this->client()->get($this->objectUrl($path));

        if (! $response->successful()) {
            throw UnableToReadFile::fromLocation($path, $response->body());
        }

        return $response->body();
    }

    public function readStream(string $path)
    {
        $contents = $this->read($path);
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path, 'Unable to open a temp stream.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        $response = $this->client()->delete($this->objectUrl($path));

        if (! $response->successful() && $response->status() !== 404) {
            throw UnableToDeleteFile::atLocation($path, $response->body());
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            foreach ($this->listContents($path, true) as $item) {
                if ($item instanceof FileAttributes) {
                    $this->delete($item->path());
                }
            }
        } catch (\Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->write(trim($path, '/').'/.keep', '', $config);
        } catch (\Throwable $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage());
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Private buckets only. Visibility is enforced by the service role.
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        $info = $this->objectInfo($path);

        return new FileAttributes($path, null, null, null, $info['mimetype'] ?? null);
    }

    public function lastModified(string $path): FileAttributes
    {
        $info = $this->objectInfo($path);
        $timestamp = isset($info['updated_at']) ? strtotime((string) $info['updated_at']) : false;

        return new FileAttributes($path, null, null, $timestamp ?: null);
    }

    public function fileSize(string $path): FileAttributes
    {
        $info = $this->objectInfo($path);

        return new FileAttributes($path, isset($info['metadata']['size']) ? (int) $info['metadata']['size'] : ($info['size'] ?? null));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = trim($path, '/');
        $offset = 0;

        do {
            $response = $this->client()->post($this->endpoint('/object/list/'.$this->bucket), [
                'prefix' => $prefix === '' ? '' : $prefix.'/',
                'limit' => 100,
                'offset' => $offset,
            ]);

            if (! $response->successful()) {
                return;
            }

            $items = $response->json();

            if (! is_array($items) || $items === []) {
                return;
            }

            foreach ($items as $item) {
                $name = ltrim($prefix.'/'.($item['name'] ?? ''), '/');

                if (! empty($item['id'])) {
                    yield new FileAttributes(
                        $name,
                        isset($item['metadata']['size']) ? (int) $item['metadata']['size'] : null,
                        Visibility::PRIVATE,
                        isset($item['updated_at']) ? strtotime((string) $item['updated_at']) ?: null : null,
                        $item['metadata']['mimetype'] ?? null,
                    );

                    continue;
                }

                yield new DirectoryAttributes($name);

                if ($deep) {
                    yield from $this->listContents($name, true);
                }
            }

            $offset += count($items);
        } while (count($items) === 100);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (\Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $response = $this->client()->post($this->endpoint('/object/copy'), [
            'bucketId' => $this->bucket,
            'sourceKey' => $source,
            'destinationKey' => $destination,
        ]);

        if ($response->successful()) {
            return;
        }

        try {
            $this->write($destination, $this->read($source), $config);
        } catch (\Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function objectInfo(string $path): array
    {
        $info = $this->client()->get($this->endpoint('/object/info/'.$this->bucket.'/'.ltrim($path, '/')));

        if ($info->successful() && is_array($info->json())) {
            return $info->json();
        }

        $head = $this->client()->head($this->objectUrl($path));

        if (! $head->successful()) {
            throw UnableToRetrieveMetadata::create($path, 'info', $head->body());
        }

        return [
            'size' => (int) $head->header('Content-Length'),
            'mimetype' => $head->header('Content-Type'),
            'updated_at' => $head->header('Last-Modified'),
        ];
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->serviceRoleKey)
            ->withHeaders([
                'apikey' => $this->serviceRoleKey,
            ])
            ->timeout(60)
            ->acceptJson();
    }

    private function objectUrl(string $path): string
    {
        return $this->endpoint('/object/'.$this->bucket.'/'.ltrim($path, '/'));
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->projectUrl, '/').'/storage/v1'.str_replace('//', '/', $path);
    }

    private function contentType(string $path, Config $config): string
    {
        return (string) ($config->get('mimetype') ?: $config->get('ContentType') ?: 'application/octet-stream');
    }
}
