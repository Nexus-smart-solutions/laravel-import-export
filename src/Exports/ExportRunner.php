<?php

namespace Nexus\ImportExport\Exports;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nexus\ImportExport\Events\ExportChanged;
use Nexus\ImportExport\Models\Export;
use Nexus\ImportExport\Models\ExportPart;
use Nexus\ImportExport\Services\DataAccess;
use Nexus\ImportExport\Writers\SpreadsheetWriter;

final class ExportRunner
{
    public function __construct(private readonly DataAccess $access, private readonly ExportQuery $queries, private readonly ExportMapper $mapper, private readonly PartStore $parts) {}

    public function step(string $id, int $revision): bool
    {
        $started = microtime(true);
        $token = (string) Str::uuid();
        $claimed = Export::query()->whereKey($id)->where('revision', $revision)->whereNotIn('status', ['completed', 'failed', 'cancelled', 'expired'])
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->update(['lease_token' => $token, 'lease_expires_at' => now()->addSeconds(config('import-export.exports.lease_seconds', 3660)), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return false;
        }
        $export = Export::query()->findOrFail($id);
        try {
            if ($export->cancel_requested_at !== null) {
                $this->commit($export, $token, ['status' => 'cancelled', 'expires_at' => now()->addDays(config('import-export.exports.retention_days', 7))], allowCancel: true);
                ExportChanged::dispatch($id, 'cancelled', $export->context);

                return true;
            }
            $definition = $this->access->definition($export->resource, $export->context);
            if ($definition::class !== $export->definition || $definition->version() !== $export->definition_version) {
                throw new \RuntimeException('Definition version changed during export.');
            }
            if ($export->prepared_at === null) {
                $base = $this->queries->base($definition, $export->request);
                [$sort, $key] = $this->queries->sort($definition, $export->request);
                $highWater = (clone $base)->max($base->getModel()->qualifyColumn($key));
                if ((clone $base)->whereNull($sort)->exists()) {
                    throw new \RuntimeException('Export sort columns must be non-null.');
                }
                $total = $highWater === null ? 0 : (clone $base)->where($key, '<=', $highWater)->count();
                $this->commit($export, $token, ['status' => 'processing', 'prepared_at' => now(), 'started_at' => now(), 'total_rows' => $total, 'high_water' => $highWater, 'read_complete' => $total === 0]);
            } elseif (! $export->read_complete) {
                $records = $this->queries->page($definition, $export)->get();
                [$sort, $key] = $this->queries->sort($definition, $export->request);
                $last = $records->last();
                if ($last !== null && ($last->getRawOriginal($key) === null || $last->getRawOriginal($sort) === null)) {
                    throw new \RuntimeException('query() must select the primary key and sort column.');
                }
                $part = $records->isEmpty() ? null : $this->parts->write($export, $token, $this->mapper->rows($definition, $export->request['fields'], $records, $export->request['compatible']));
                $this->commit($export, $token, [
                    'status' => $records->isEmpty() ? 'finalizing' : 'processing',
                    'processed_rows' => $export->processed_rows + $records->count(),
                    'parts_count' => $export->parts_count + ($part !== null ? 1 : 0),
                    'checkpoint' => $last === null ? $export->checkpoint : ['sort' => $last->getRawOriginal($sort), 'key' => $last->getRawOriginal($key)],
                    'read_complete' => $records->count() < max(1, (int) config('import-export.exports.query_chunk_size', 2000)),
                ], $part);
            } else {
                $this->assemble($export, $token);
            }
            Log::info('export.step.completed', ['operation' => 'export', 'export_id' => $id, 'definition' => $export->definition, 'revision' => $revision,
                'duration_seconds' => microtime(true) - $started, 'memory_bytes' => memory_get_usage(true)]);
            ExportChanged::dispatch($id, $export->refresh()->status->value, $export->context);

            return true;
        } finally {
            Export::query()->whereKey($id)->where('lease_token', $token)->update(['lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
        }
    }

    private function commit(Export $export, string $token, array $attributes, ?array $part = null, bool $allowCancel = false): void
    {
        $export->getConnection()->transaction(function () use ($export, $token, $attributes, $part, $allowCancel): void {
            $current = Export::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();
            if ($current->lease_token !== $token || $current->revision !== $export->revision || (! $allowCancel && $current->cancel_requested_at !== null)) {
                throw new \RuntimeException('Export lease lost or cancellation requested.');
            }
            if ($part !== null) {
                ExportPart::query()->create(['export_id' => $export->id, 'part_number' => $export->parts_count + 1, ...$part]);
            }
            $current->forceFill([...$attributes, 'revision' => $current->revision + 1, 'attempts' => $current->attempts + 1, 'lease_token' => null, 'lease_expires_at' => null])->save();
        }, attempts: 3);
    }

    private function assemble(Export $export, string $token): void
    {
        Export::query()->whereKey($export->id)->where('lease_token', $token)->update(['status' => 'finalizing']);
        $local = tempnam(sys_get_temp_dir(), 'data-export-');
        if ($local === false) {
            throw new \RuntimeException('Cannot allocate export temporary file.');
        }
        try {
            $rows = function () use ($export, $token): iterable {
                $total = 0;
                foreach (ExportPart::query()->where('export_id', $export->id)->lazyById(100) as $part) {
                    $updated = Export::query()->whereKey($export->id)->where('lease_token', $token)->whereNull('cancel_requested_at')
                        ->update(['lease_expires_at' => now()->addSeconds(config('import-export.exports.lease_seconds', 3660)), 'updated_at' => now()]);
                    if (! $updated) {
                        throw new \RuntimeException('Export assembly lease lost or cancelled.');
                    }
                    foreach ($this->parts->rows($export, $part) as $row) {
                        $total++;
                        yield $row;
                    }
                }
                if ($total !== $export->processed_rows) {
                    throw new \RuntimeException('Export assembly row count mismatch.');
                }
            };
            app(SpreadsheetWriter::class)->write($local, $export->format, $export->headers, $rows());
            $path = $this->parts->prefix($export).'/result-'.$token.'.'.$export->format;
            $stream = fopen($local, 'rb');
            try {
                if (! Storage::disk($export->disk)->writeStream($path, $stream, ['visibility' => 'private'])) {
                    throw new \RuntimeException('Cannot store export result.');
                }
            } finally {
                fclose($stream);
            }
            $this->commit($export, $token, ['status' => 'completed', 'file_path' => $path, 'completed_at' => now(), 'expires_at' => now()->addDays(config('import-export.exports.retention_days', 7)), 'error_message' => null]);
        } finally {
            @unlink($local);
        }
    }
}
