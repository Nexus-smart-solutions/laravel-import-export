<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Str;
use JsonException;
use Nexus\ImportExport\Contracts\ImportAuthorizer;
use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Enums\FailureStage;
use Nexus\ImportExport\Enums\IdempotencyStrategy;
use Nexus\ImportExport\Enums\ImportMode;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Events\ImportCreated;
use Nexus\ImportExport\Events\ImportFailed;
use Nexus\ImportExport\Events\ImportQueued;
use Nexus\ImportExport\Exceptions\DuplicateImportException;
use Nexus\ImportExport\Exceptions\ImportConfigurationException;
use Nexus\ImportExport\Jobs\PrepareImportJob;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\State\ImportStateMachine;
use Nexus\ImportExport\Support\CanonicalJson;
use Throwable;

final class ImportDispatcher
{
    public function __construct(
        private readonly FilePreflightValidator $preflight,
        private readonly ContextNormalizer $contexts,
        private readonly ImportDefinitionRegistry $definitions,
        private readonly ImportFingerprint $fingerprints,
        private readonly ImportAuthorizer $authorizer,
        private readonly FilesystemManager $filesystems,
        private readonly ImportStateMachine $states,
        private readonly QueueManager $queues,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     */
    public function dispatchDefinition(
        string $definitionClass,
        UploadedFile $file,
        ?object $actor = null,
        array $context = [],
        array $options = [],
        ?IdempotencyStrategy $idempotency = null,
    ): Import {
        $this->assertAsynchronousQueue();
        $this->preflight->validate($file);
        $definition = $this->definitions->makeClass($definitionClass);
        $type = $this->definitions->typeFor($definitionClass);
        $context = $this->contexts->normalize($context);
        $options = $this->normalizeOptions($options);
        if (($options['dry_run'] ?? false) && $definition->mode() !== ImportMode::PARTIAL) {
            throw new \InvalidArgumentException('Dry runs require PARTIAL mode.');
        }
        if ($definition instanceof DataDefinition) {
            $trusted = app(DataAccess::class)->context();
            if ($context !== $trusted) {
                throw new AuthorizationException('Tenant context must come from CurrentContext.');
            }
            $definition->inContext($context);
            app(DataAccess::class)->assertActor($definition, $actor, $type);
            if (! $definition->canImport($actor)) {
                throw new AuthorizationException;
            }
        }

        if (! $this->authorizer->canCreate($actor, $type, $context)) {
            throw new AuthorizationException('You are not allowed to create this import.');
        }

        $strategy = $idempotency ?? IdempotencyStrategy::from(
            (string) config('bulk-imports.idempotency.default_strategy', 'return_existing'),
        );
        $realPath = (string) $file->getRealPath();
        $fileHash = hash_file('sha256', $realPath);
        if (! is_string($fileHash)) {
            throw new \RuntimeException('The upload hash could not be calculated.');
        }

        [$actorType, $actorId] = $this->actorIdentity($actor);
        $fingerprint = $this->fingerprints->make(
            $fileHash,
            $definitionClass,
            $definition->version(),
            $context,
            $options,
            $actorType,
            $actorId,
        );
        $deduplicationKey = $strategy === IdempotencyStrategy::ALLOW
            ? hash('sha256', $fingerprint.'|'.Str::uuid())
            : $fingerprint;

        if ($strategy !== IdempotencyStrategy::ALLOW
            && ($existing = Import::query()->where('deduplication_key', $deduplicationKey)->first())) {
            return $this->resolveDuplicate($existing, $strategy, $actor);
        }

        $disk = (string) config('bulk-imports.files.disk', 'local');
        $extension = strtolower($file->getClientOriginalExtension());
        $path = trim((string) config('bulk-imports.files.directory', 'bulk-imports'), '/')
            .'/incoming/'.now()->format('Y/m').'/'.Str::ulid().'.'.$extension;
        $originalFilename = $this->sanitizeOriginalFilename($file->getClientOriginalName());

        $stream = fopen($realPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('The uploaded temporary file could not be opened.');
        }

        try {
            if (! $this->filesystems->disk($disk)->writeStream($path, $stream, [
                'visibility' => (string) config('bulk-imports.files.visibility', 'private'),
            ])) {
                throw new \RuntimeException('The uploaded file could not be stored.');
            }
        } finally {
            fclose($stream);
        }

        try {
            $import = Import::query()->create([
                'type' => $type,
                'definition' => $definitionClass,
                'definition_version' => $definition->version(),
                'original_filename' => $originalFilename,
                'disk' => $disk,
                'file_path' => $path,
                'file_hash' => $fileHash,
                'fingerprint' => $fingerprint,
                'deduplication_key' => $deduplicationKey,
                'status' => ImportStatus::UPLOADED,
                'mode' => $definition->mode(),
                'duplicate_strategy' => $definition->duplicateStrategy(),
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'context' => $context,
                'context_hash' => hash('sha256', CanonicalJson::encode($context)),
                'options' => $options,
            ]);
        } catch (QueryException $exception) {
            $this->filesystems->disk($disk)->delete($path);
            $existing = $strategy === IdempotencyStrategy::ALLOW
                ? null
                : Import::query()->where('deduplication_key', $deduplicationKey)->first();

            if ($existing !== null) {
                return $this->resolveDuplicate($existing, $strategy, $actor);
            }

            throw $exception;
        }

        try {
            ImportCreated::dispatch($import);
            $import = $this->states->transition($import, ImportStatus::QUEUED);
            ImportQueued::dispatch($import);
            $pending = PrepareImportJob::dispatch($import->id, $context);
            if (($connection = config('bulk-imports.queue.connection')) !== null) {
                $pending->onConnection($connection);
            }
            $pending->onQueue((string) config('bulk-imports.queue.name', 'imports'));
            unset($pending);
            Import::query()->whereKey($import->id)->update([
                'preparation_dispatched_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $current = $import->refresh();
            if (! $current->status->isTerminal() && $this->states->can($current->status, ImportStatus::FAILED)) {
                $current = $this->states->transition($current, ImportStatus::FAILED, [
                    'failure_stage' => FailureStage::PREPARATION->value,
                    'error_message' => Str::limit($exception->getMessage(), 4000),
                    'failed_at' => now(),
                ]);
                ImportFailed::dispatch($current, $exception->getMessage());
            }
            throw $exception;
        }

        return $import->refresh();
    }

    private function resolveDuplicate(
        Import $existing,
        IdempotencyStrategy $strategy,
        ?object $actor,
    ): Import {
        if (! $this->authorizer->canView($actor, $existing)) {
            throw new AuthorizationException('A matching import exists but is not visible to this actor.');
        }

        if ($strategy === IdempotencyStrategy::REJECT) {
            throw new DuplicateImportException($existing);
        }

        return $existing;
    }

    /** @return array{?string, ?string} */
    private function actorIdentity(?object $actor): array
    {
        if ($actor === null || ! method_exists($actor, 'getKey')) {
            return [null, null];
        }

        $type = method_exists($actor, 'getMorphClass') ? $actor->getMorphClass() : $actor::class;

        return [(string) $type, (string) $actor->getKey()];
    }

    private function sanitizeOriginalFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?: 'import';

        return Str::limit($filename, 255, '');
    }

    private function assertAsynchronousQueue(): void
    {
        if (config('bulk-imports.queue.allow_sync', false)) {
            return;
        }

        $connection = $this->queues->connection(config('bulk-imports.queue.connection'));
        if ($connection instanceof SyncQueue) {
            throw new ImportConfigurationException(
                'The bulk import queue cannot use the sync driver because row processing must run outside the request.',
            );
        }
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function normalizeOptions(array $options): array
    {
        $this->assertJsonSafe($options);
        if (isset($options['dry_run']) && ! is_bool($options['dry_run'])) {
            throw new \InvalidArgumentException('dry_run must be boolean.');
        }
        if (isset($options['mapping']) && ! is_array($options['mapping'])) {
            throw new \InvalidArgumentException('mapping must be a header-to-field object.');
        }

        if (array_key_exists('chunk_size', $options)) {
            $chunkSize = $options['chunk_size'];
            $minimum = (int) config('bulk-imports.limits.min_chunk_size', 1);
            $maximum = (int) config('bulk-imports.limits.max_chunk_size', 5000);
            if (! is_int($chunkSize) || $chunkSize < $minimum || $chunkSize > $maximum) {
                throw new \InvalidArgumentException(
                    "Import option [chunk_size] must be an integer between {$minimum} and {$maximum}.",
                );
            }
        }

        try {
            $json = CanonicalJson::encode($options);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Import options must be valid UTF-8 JSON data.', 0, $exception);
        }
        if (strlen($json) > 16384) {
            throw new \InvalidArgumentException('Import options exceed 16 KiB.');
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertJsonSafe(mixed $value, int $depth = 0): void
    {
        if ($depth > 32) {
            throw new \InvalidArgumentException('Import options exceed the maximum nesting depth.');
        }
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new \InvalidArgumentException('Import options may contain only finite numbers.');
            }

            return;
        }
        if (! is_array($value)) {
            throw new \InvalidArgumentException('Import options must contain only JSON-safe values.');
        }

        foreach ($value as $item) {
            $this->assertJsonSafe($item, $depth + 1);
        }
    }
}
