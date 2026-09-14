<?php

namespace Nexus\ImportExport\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nexus\ImportExport\Contracts\ImportAuthorizer;
use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Enums\ChunkStatus;
use Nexus\ImportExport\Enums\IdempotencyStrategy;
use Nexus\ImportExport\Exceptions\DuplicateImportException;
use Nexus\ImportExport\Exceptions\FileValidationException;
use Nexus\ImportExport\Exceptions\InvalidStateTransition;
use Nexus\ImportExport\Http\Requests\StoreImportRequest;
use Nexus\ImportExport\Http\Resources\ImportFailureResource;
use Nexus\ImportExport\Http\Resources\ImportResource;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Services\CancelImport;
use Nexus\ImportExport\Services\ContextNormalizer;
use Nexus\ImportExport\Services\DataAccess;
use Nexus\ImportExport\Services\DataDescription;
use Nexus\ImportExport\Services\ImportDefinitionRegistry;
use Nexus\ImportExport\Services\ImportDispatcher;
use Nexus\ImportExport\Services\RetryImport;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ImportController extends Controller
{
    public function __construct(private readonly ImportAuthorizer $authorizer) {}

    public function index(Request $request)
    {
        $query = $this->authorizer->scopeVisible(Import::query(), $request->user())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')));

        $this->withChunkCounts($query);

        return ImportResource::collection($query->latest()->paginate(min(100, max(1, $request->integer('per_page', 20)))));
    }

    public function store(
        StoreImportRequest $request,
        ImportDefinitionRegistry $definitions,
        ImportDispatcher $dispatcher,
    ): JsonResponse {
        $type = $request->string('type')->toString();
        try {
            $import = $dispatcher->dispatchDefinition(
                $definitions->classFor($type),
                $request->file('file'),
                $request->user(),
                $request->input('context', []),
                $request->input('options', []),
                $request->filled('idempotency')
                    ? IdempotencyStrategy::from($request->string('idempotency')->toString())
                    : null,
            );
        } catch (FileValidationException $exception) {
            return response()->json(['message' => 'The import source is invalid.', 'errors' => $exception->errors], 422);
        } catch (DuplicateImportException $exception) {
            return response()->json(['message' => 'A matching import already exists.', 'data' => [
                'id' => $exception->existingImport->id,
                'status' => $exception->existingImport->status->value,
            ]], 409);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        $import = $this->findWithChunkCounts($import->id);

        return (new ImportResource($import))->response()->setStatusCode(202);
    }

    public function show(Request $request, Import $import): ImportResource
    {
        $this->assertAllowed($this->authorizer->canView($request->user(), $import));

        return new ImportResource($this->findWithChunkCounts($import->id));
    }

    public function failures(Request $request, Import $import)
    {
        $this->assertAllowed($this->authorizer->canView($request->user(), $import));

        return ImportFailureResource::collection(
            $import->failures()->orderBy('row_number')->orderBy('id')
                ->paginate(min(100, max(1, $request->integer('per_page', 50)))),
        );
    }

    public function cancel(Request $request, Import $import, CancelImport $cancel): ImportResource|JsonResponse
    {
        $this->assertAllowed($this->authorizer->canCancel($request->user(), $import));

        try {
            $cancel->handle($import);
        } catch (InvalidStateTransition $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return new ImportResource($this->findWithChunkCounts($import->id));
    }

    public function retry(Request $request, Import $import, RetryImport $retry): JsonResponse
    {
        $this->assertAllowed($this->authorizer->canRetry($request->user(), $import));

        try {
            $retry->handle($import);
        } catch (InvalidStateTransition $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return (new ImportResource($this->findWithChunkCounts($import->id)))->response()->setStatusCode(202);
    }

    public function downloadErrors(
        Request $request,
        Import $import,
        FilesystemManager $filesystems,
    ): StreamedResponse {
        $this->assertAllowed($this->authorizer->canView($request->user(), $import));
        abort_if($import->error_report_path === null, 404, 'The error report is not available yet.');

        return $filesystems->disk($import->error_report_disk)->download(
            $import->error_report_path,
            "import-{$import->id}-errors.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'],
        );
    }

    public function template(
        Request $request,
        string $type,
        ImportDefinitionRegistry $definitions,
        ContextNormalizer $contexts,
    ): JsonResponse {
        $request->validate(['context' => ['sometimes', 'array']]);
        try {
            $context = $contexts->normalize($request->input('context', []));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        $this->assertAllowed($this->authorizer->canCreate($request->user(), $type, $context));
        $definition = $definitions->make($type);
        if ($definition instanceof DataDefinition) {
            $access = app(DataAccess::class);
            if ($request->exists('context') && $context !== $access->context()) {
                throw new AuthorizationException('Context is server-controlled.');
            }
            $definition = $access->definition($type);
            $access->assertActor($definition, $request->user(), $type);
            $access->check($definition->canDownloadTemplate($request->user()));

            return response()->json(['data' => app(DataDescription::class)->describe($definition, $request->user())]);
        }

        return response()->json(['data' => [
            'type' => $type,
            'columns' => $definition->columns(),
            'uniqueBy' => $definition->uniqueBy(),
            'targetUniqueBy' => $definition->targetUniqueBy(),
            'mode' => $definition->mode()->value,
            'duplicateStrategy' => $definition->duplicateStrategy()->value,
            'rules' => collect($definition->rules())->map(
                static fn (mixed $rules): array => collect(is_array($rules) ? $rules : explode('|', (string) $rules))
                    ->map(static fn (mixed $rule): string => is_string($rule) ? $rule : $rule::class)
                    ->all(),
            ),
        ]]);
    }

    private function assertAllowed(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    private function withChunkCounts($query): void
    {
        $query->withCount([
            'chunks as chunks_completed_count' => fn ($query) => $query->where('status', ChunkStatus::COMPLETED->value),
            'chunks as chunks_processing_count' => fn ($query) => $query->where('status', ChunkStatus::PROCESSING->value),
            'chunks as chunks_queued_count' => fn ($query) => $query->where('status', ChunkStatus::QUEUED->value),
            'chunks as chunks_failed_count' => fn ($query) => $query->where('status', ChunkStatus::FAILED->value),
            'chunks as chunks_cancelled_count' => fn ($query) => $query->where('status', ChunkStatus::CANCELLED->value),
        ]);
    }

    private function findWithChunkCounts(string $id): Import
    {
        $query = Import::query()->whereKey($id);
        $this->withChunkCounts($query);

        return $query->firstOrFail();
    }
}
