<?php

namespace Nexus\ImportExport;

use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Nexus\ImportExport\Enums\ExportStatus;
use Nexus\ImportExport\Events\ExportChanged;
use Nexus\ImportExport\Exceptions\ImportConfigurationException;
use Nexus\ImportExport\Exports\ExportQuery;
use Nexus\ImportExport\Jobs\ProcessExportJob;
use Nexus\ImportExport\Models\Export;
use Nexus\ImportExport\Services\DataAccess;
use Nexus\ImportExport\Support\CanonicalJson;

final class ExportManager
{
    public function __construct(private readonly DataAccess $access) {}

    public function dispatch(string $resource, object $actor, array $request = []): Export
    {
        if (! config('import-export.exports.allow_sync', false) && app('queue')->connection(config('import-export.exports.connection')) instanceof SyncQueue) {
            throw new ImportConfigurationException('Exports require an asynchronous queue connection.');
        }
        if (strlen(CanonicalJson::encode($request)) > 16384) {
            $this->invalid('request', 'Export request exceeds 16 KiB.');
        }
        $definition = $this->access->definition($resource);
        $this->access->assertActor($definition, $actor, $resource);
        $this->access->check($definition->canExport($actor));
        [$actorType, $actorId] = $this->access->identity($actor);
        if (array_diff(array_keys($request), ['format', 'fields', 'filters', 'ids', 'sort', 'direction', 'compatible']) !== []) {
            $this->invalid('request', 'Unknown export option.');
        }
        $request += ['format' => 'csv', 'filters' => [], 'ids' => [], 'sort' => null, 'direction' => 'asc', 'compatible' => false];
        if (! in_array($request['format'], ['csv', 'xlsx'], true) || ! is_bool($request['compatible'])) {
            $this->invalid('format', 'Use csv or xlsx and a boolean compatible option.');
        }
        $allowed = array_filter($definition->fieldMap(), fn ($field) => $field->canExport && $definition->canExportField($actor, $field)
            && (! $request['compatible'] || ($field->canImport && $field->inTemplate)));
        $request['fields'] ??= array_keys($allowed);
        if (! is_array($request['fields']) || ! array_is_list($request['fields']) || $request['fields'] === []) {
            $this->invalid('fields', 'Choose at least one export field.');
        }
        foreach ($request['fields'] as $name) {
            if (! is_string($name) || ! isset($allowed[$name])) {
                $this->invalid('fields', 'An export field is unavailable or unauthorized.');
            }
        }
        if (count(array_unique($request['fields'])) !== count($request['fields'])) {
            $this->invalid('fields', 'Repeated export fields are unsupported.');
        }
        if ($request['compatible'] && array_diff($definition->requiredColumns(), $request['fields']) !== []) {
            $this->invalid('fields', 'An import-compatible export must include required and business-key fields.');
        }
        if (! is_array($request['filters']) || array_diff(array_keys($request['filters']), array_keys($definition->filters())) !== []) {
            $this->invalid('filters', 'Unknown filter.');
        }
        if (! is_array($request['ids']) || ! array_is_list($request['ids']) || count($request['ids']) > config('import-export.exports.selected_ids_limit', 1000)) {
            $this->invalid('ids', 'Selected IDs exceed the allowed bound.');
        }
        foreach ($request['ids'] as $id) {
            if ((! is_string($id) && ! is_int($id)) || strlen((string) $id) > 255) {
                $this->invalid('ids', 'Invalid selected ID.');
            }
        }
        if (($request['sort'] !== null && (! is_string($request['sort']) || ! isset($definition->sorts()[$request['sort']]))) || ! in_array($request['direction'], ['asc', 'desc'], true)) {
            $this->invalid('sort', 'Unknown sort or direction.');
        }
        app(ExportQuery::class)->base($definition, $request); // Validate trusted filter callbacks without running the query.
        $headers = array_map(fn ($name) => $request['compatible'] ? $allowed[$name]->templateHeader : $allowed[$name]->exportHeader, $request['fields']);
        if (count(array_unique($headers)) !== count($headers)) {
            $this->invalid('fields', 'Selected fields have duplicate export headers.');
        }
        $export = Export::query()->create([
            'resource' => $resource, 'definition' => $definition::class, 'definition_version' => $definition->version(),
            'format' => $request['format'], 'disk' => config('import-export.exports.disk') ?? config('bulk-imports.files.disk', 'local'),
            'filename' => preg_replace('/[^A-Za-z0-9_-]/', '-', $resource).'-'.now()->format('Ymd-His').'.'.$request['format'],
            'status' => ExportStatus::QUEUED, 'actor_type' => $actorType, 'actor_id' => $actorId,
            'context' => $definition->context(), 'context_hash' => hash('sha256', CanonicalJson::encode($definition->context())),
            'request' => $request, 'headers' => $headers,
        ]);
        $export->refresh();
        ExportChanged::dispatch($export->id, 'created', $export->context);
        $this->schedule($export);

        return $export->refresh();
    }

    public function schedule(Export $export): void
    {
        ProcessExportJob::dispatch($export->id, $export->context, $export->revision)
            ->onConnection(config('import-export.exports.connection'))->onQueue(config('import-export.exports.queue', 'exports'))->afterCommit();
    }

    public function download(Export $export, object $actor)
    {
        $this->access->authorizeExport($export, $actor);
        abort_unless($export->status === ExportStatus::COMPLETED && $export->file_path !== null && $export->expires_at?->isFuture(), 410, 'Export is unavailable or expired.');

        return Storage::disk($export->disk)->download($export->file_path, $export->filename, [
            'Content-Type' => $export->format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function cancel(Export $export, object $actor): Export
    {
        $this->access->authorizeExport($export, $actor);
        Export::query()->whereKey($export->id)->whereNotIn('status', ['completed', 'failed', 'cancelled', 'expired'])->update(['status' => 'cancelling', 'cancel_requested_at' => now(), 'updated_at' => now()]);
        $this->schedule($export->refresh());

        return $export->refresh();
    }

    public function retry(Export $export, object $actor): Export
    {
        $definition = $this->access->authorizeExport($export, $actor);
        abort_unless($definition->version() === $export->definition_version, 409, 'Definition version changed; create a new export.');
        $updated = Export::query()->whereKey($export->id)->where('status', 'failed')->whereNull('cancel_requested_at')->where(function ($query): void {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })
            ->update(['status' => $export->read_complete ? 'finalizing' : 'processing', 'failed_at' => null, 'error_message' => null, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
        abort_unless($updated === 1, 409, 'Export cannot be retried.');
        $this->schedule($export->refresh());

        return $export->refresh();
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
