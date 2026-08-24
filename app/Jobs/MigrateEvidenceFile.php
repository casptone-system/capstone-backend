<?php

namespace App\Jobs;

use App\Models\StorageMigrationItem;
use App\Services\EvidenceStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MigrateEvidenceFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public int $itemId,
        public bool $deleteSource = false
    ) {
    }

    public function handle(EvidenceStorage $evidenceStorage): void
    {
        $item = StorageMigrationItem::find($this->itemId);

        if (! $item) {
            return;
        }

        $fromDisk = $item->direction === StorageMigrationItem::DIRECTION_TO_R2 ? 'local' : 's3';
        $toDisk = $item->direction === StorageMigrationItem::DIRECTION_TO_R2 ? 's3' : 'local';
        $path = $item->file_path;

        try {
            if (! Storage::disk($fromDisk)->exists($path)) {
                if (Storage::disk($toDisk)->exists($path)) {
                    $item->update([
                        'status' => StorageMigrationItem::STATUS_SKIPPED,
                        'error' => "Source missing on {$fromDisk}; destination already has the object.",
                        'processed_at' => now(),
                    ]);

                    return;
                }

                $item->update([
                    'status' => StorageMigrationItem::STATUS_FAILED,
                    'error' => "Source file not found on disk [{$fromDisk}]: {$path}",
                    'processed_at' => now(),
                ]);

                return;
            }

            $sourceChecksum = $evidenceStorage->checksum($path, $fromDisk);
            $item->source_checksum = $sourceChecksum;
            $item->file_size = Storage::disk($fromDisk)->size($path);

            $alreadyThere = Storage::disk($toDisk)->exists($path)
                && hash_equals($sourceChecksum, $evidenceStorage->checksum($path, $toDisk));

            if (! $alreadyThere) {
                $copied = $evidenceStorage->copyBetween($path, $fromDisk, $toDisk);

                if (! $copied) {
                    throw new \RuntimeException("Failed to copy {$path} from {$fromDisk} to {$toDisk}.");
                }

                $item->status = StorageMigrationItem::STATUS_COPIED;
                $item->save();
            }

            $destinationChecksum = $evidenceStorage->checksum($path, $toDisk);

            if (! hash_equals($sourceChecksum, $destinationChecksum)) {
                Storage::disk($toDisk)->delete($path);
                throw new \RuntimeException("Checksum mismatch for {$path}. Source={$sourceChecksum} destination={$destinationChecksum}");
            }

            $item->destination_checksum = $destinationChecksum;
            $item->status = StorageMigrationItem::STATUS_VERIFIED;
            $item->error = null;
            $item->processed_at = now();
            $item->save();

            if ($this->deleteSource) {
                Storage::disk($fromDisk)->delete($path);
                $item->status = StorageMigrationItem::STATUS_SOURCE_DELETED;
                $item->processed_at = now();
                $item->save();
            }
        } catch (\Throwable $e) {
            Log::error('Evidence file migration failed.', [
                'item_id' => $item->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            $item->update([
                'status' => StorageMigrationItem::STATUS_FAILED,
                'error' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            throw $e;
        }
    }
}
