<?php

namespace Nexus\ImportExport\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Nexus\ImportExport\Auth\GuestAccess;
use Nexus\ImportExport\ExportManager;
use Nexus\ImportExport\Exports\PartStore;
use Nexus\ImportExport\Models\Export;
use Nexus\ImportExport\Models\ExportPart;
use Nexus\ImportExport\Notifications\NotificationDispatcher;

final class MaintainExportsCommand extends Command
{
    protected $signature = 'import-export:maintain {--recover : Requeue stale work on the current metadata connection}';

    protected $description = 'Expire terminal exports and optionally recover stale export steps';

    public function handle(ExportManager $manager, PartStore $parts): int
    {
        if ($this->option('recover')) {
            foreach (Export::query()->whereIn('status', ['queued', 'preparing', 'processing', 'finalizing', 'cancelling'])
                ->where('updated_at', '<', now()->subSeconds(config('bulk-imports.queue.recovery_grace_seconds', 300)))
                ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))->lazyById(100) as $export) {
                $manager->schedule($export);
            }
        }
        foreach (Export::query()->whereIn('status', ['completed', 'failed', 'cancelled', 'expired'])->where('expires_at', '<=', now())->lazyById(100) as $export) {
            $claimed = Export::query()->whereKey($export->id)->whereIn('status', ['completed', 'failed', 'cancelled', 'expired'])
                ->where('expires_at', '<=', now())->update(['status' => 'expired', 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $prefix = $parts->prefix($export);
            $disk = Storage::disk($export->disk);
            if ($disk->directoryExists($prefix) && ! $disk->deleteDirectory($prefix)) {
                $this->error('Unable to delete export '.$export->id);

                continue;
            }
            ExportPart::query()->where('export_id', $export->id)->delete();
            Export::query()->whereKey($export->id)->update(['file_path' => null, 'expires_at' => null]);
        }

        app(GuestAccess::class)->tokens()->where('expires_at', '<=', now()->subDays(config('import-export.guests.retention_days', 7)))->delete();
        app(NotificationDispatcher::class)->receipts()
            ->where(fn ($q) => $q->whereNotNull('sent_at')->orWhereNotNull('skipped_at'))
            ->where('updated_at', '<', now()->subDays(config('import-export.notifications.retention_days', 30)))->delete();

        return self::SUCCESS;
    }
}
