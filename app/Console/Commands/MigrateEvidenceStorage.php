<?php

namespace App\Console\Commands;

use App\Jobs\MigrateEvidenceFile;
use App\Models\DocumentVersion;
use App\Models\RoleStorageFile;
use App\Models\StorageMigrationItem;
use App\Services\EvidenceStorage;
use Illuminate\Console\Command;

class MigrateEvidenceStorage extends Command
{
    protected $signature = 'storage:migrate-evidence
                            {--direction=to-r2 : to-r2 copies local → R2; from-r2 copies R2 → local}
                            {--delete-source : Delete the source object only after checksum verification}
                            {--dry-run : Inventory files and write tracking rows without copying}
                            {--limit= : Maximum number of files to process this run}
                            {--retry-failed : Re-queue items that previously failed}
                            {--only= : documents|role-storage}
                            {--sync : Run jobs inline even if the queue is not sync}';

    protected $description = 'Copy evidence documents and role-storage files between local disk and Cloudflare R2. Resumable and reversible.';

    public function handle(EvidenceStorage $evidenceStorage): int
    {
        $directionInput = str_replace('-', '_', (string) $this->option('direction'));
        $direction = $directionInput === 'from_r2'
            ? StorageMigrationItem::DIRECTION_FROM_R2
            : StorageMigrationItem::DIRECTION_TO_R2;

        $fromDisk = $direction === StorageMigrationItem::DIRECTION_TO_R2 ? 'local' : 's3';
        $toDisk = $direction === StorageMigrationItem::DIRECTION_TO_R2 ? 's3' : 'local';
        $dryRun = (bool) $this->option('dry-run');
        $deleteSource = (bool) $this->option('delete-source');
        $retryFailed = (bool) $this->option('retry-failed');
        $only = $this->option('only');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($direction === StorageMigrationItem::DIRECTION_TO_R2 && empty(config('filesystems.disks.s3.bucket'))) {
            $this->error('AWS_BUCKET is empty. Configure Cloudflare R2 credentials before migrating.');
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Evidence storage migration: %s → %s%s%s',
            $fromDisk,
            $toDisk,
            $dryRun ? ' (dry-run)' : '',
            $deleteSource ? ' (delete source after verify)' : ''
        ));

        $queued = $this->inventory($direction, $only, $retryFailed);
        $this->info("Tracking {$queued} file(s).");

        $query = StorageMigrationItem::query()
            ->where('direction', $direction)
            ->whereIn('status', $this->processableStatuses($retryFailed, $deleteSource));

        if ($limit) {
            $query->limit($limit);
        }

        $items = $query->orderBy('id')->get();

        if ($items->isEmpty()) {
            $this->info('Nothing to process.');
            $this->printSummary($direction);

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->table(
                ['ID', 'Type', 'Source ID', 'Path', 'Status'],
                $items->map(fn (StorageMigrationItem $item) => [
                    $item->id,
                    $item->source_type,
                    $item->source_id,
                    $item->file_path,
                    $item->status,
                ])->all()
            );

            $this->printSummary($direction);

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($items->count());
        $bar->start();

        foreach ($items as $item) {
            $job = new MigrateEvidenceFile($item->id, $deleteSource);

            if ($this->option('sync') || config('queue.default') === 'sync') {
                $job->handle($evidenceStorage);
            } else {
                dispatch($job);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->printSummary($direction);

        return self::SUCCESS;
    }

    private function inventory(string $direction, ?string $only, bool $retryFailed): int
    {
        $count = 0;
        $includeDocuments = $only === null || $only === 'documents';
        $includeRoleStorage = $only === null || $only === 'role-storage';

        if ($includeDocuments) {
            DocumentVersion::query()->select(['id', 'file_path', 'file_size'])->chunkById(200, function ($versions) use ($direction, &$count) {
                foreach ($versions as $version) {
                    if (! $version->file_path) {
                        continue;
                    }

                    $this->upsertItem(EvidenceStorage::SOURCE_DOCUMENT_VERSION, (int) $version->id, $version->file_path, $version->file_size, $direction);
                    $count++;
                }
            });
        }

        if ($includeRoleStorage) {
            RoleStorageFile::query()->select(['id', 'file_path', 'file_size'])->chunkById(200, function ($files) use ($direction, &$count) {
                foreach ($files as $file) {
                    if (! $file->file_path) {
                        continue;
                    }

                    $this->upsertItem(EvidenceStorage::SOURCE_ROLE_STORAGE_FILE, (int) $file->id, $file->file_path, $file->file_size, $direction);
                    $count++;
                }
            });
        }

        if ($retryFailed) {
            StorageMigrationItem::query()
                ->where('direction', $direction)
                ->where('status', StorageMigrationItem::STATUS_FAILED)
                ->update([
                    'status' => StorageMigrationItem::STATUS_PENDING,
                    'error' => null,
                ]);
        }

        return $count;
    }

    private function upsertItem(string $type, int $id, string $path, ?int $size, string $direction): void
    {
        StorageMigrationItem::query()->firstOrCreate(
            [
                'source_type' => $type,
                'source_id' => $id,
                'direction' => $direction,
            ],
            [
                'file_path' => $path,
                'file_size' => $size,
                'status' => StorageMigrationItem::STATUS_PENDING,
            ]
        );
    }

    /**
     * @return list<string>
     */
    private function processableStatuses(bool $retryFailed, bool $deleteSource): array
    {
        $statuses = [
            StorageMigrationItem::STATUS_PENDING,
            StorageMigrationItem::STATUS_COPIED,
        ];

        if ($retryFailed) {
            $statuses[] = StorageMigrationItem::STATUS_FAILED;
        }

        if ($deleteSource) {
            $statuses[] = StorageMigrationItem::STATUS_VERIFIED;
        }

        return $statuses;
    }

    private function printSummary(string $direction): void
    {
        $rows = StorageMigrationItem::query()
            ->selectRaw('status, count(*) as total')
            ->where('direction', $direction)
            ->groupBy('status')
            ->pluck('total', 'status');

        if ($rows->isEmpty()) {
            return;
        }

        $this->table(
            ['Status', 'Count'],
            $rows->map(fn ($total, $status) => [$status, $total])->values()->all()
        );
    }
}
