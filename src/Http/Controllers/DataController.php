<?php

namespace Nexus\ImportExport\Http\Controllers;

use Illuminate\Http\Request;
use Nexus\ImportExport\Auth\GuestAccess;
use Nexus\ImportExport\Auth\GuestPrincipal;
use Nexus\ImportExport\Exceptions\FileValidationException;
use Nexus\ImportExport\ExportManager;
use Nexus\ImportExport\Http\Resources\ImportResource;
use Nexus\ImportExport\ImportManager;
use Nexus\ImportExport\Models\Export;
use Nexus\ImportExport\Services\DataAccess;
use Nexus\ImportExport\Services\DataDescription;
use Nexus\ImportExport\TemplateManager;

final class DataController
{
    public function guestToken(string $resource, GuestAccess $guests)
    {
        $issued = $guests->issue($resource);

        return response()->json(['data' => ['token' => $issued['token'], 'expires_at' => $issued['expires_at']]], 201)->header('Cache-Control', 'private, no-store');
    }

    public function revokeGuest(Request $request, GuestAccess $guests)
    {
        abort_unless($request->user() instanceof GuestPrincipal, 403);
        $guests->revoke($request->user());

        return response()->noContent();
    }

    public function indexExports(Request $request, DataAccess $access)
    {
        [$type,$id] = $access->identity($request->user());
        $page = Export::query()->where('actor_type', $type)->where('actor_id', $id)->where('context_hash', $access->contextHash())
            ->orderByDesc('id')->cursorPaginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json(['data' => $page->getCollection()->map(fn (Export $export) => $this->exportData($export)),
            'links' => ['next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()]]);
    }

    public function metadata(Request $request, string $resource, DataAccess $access, DataDescription $description)
    {
        $definition = $access->definition($resource);
        $access->assertActor($definition, $request->user(), $resource);
        $access->check($definition->canImport($request->user()) || $definition->canExport($request->user()));

        return response()->json(['data' => $description->describe($definition, $request->user())]);
    }

    public function template(Request $request, string $resource, TemplateManager $manager)
    {
        if ($request->boolean('with_data')) {
            return response()->json(['data' => $this->exportData($manager->withData($resource, $request->user(), $request->only(['format', 'fields', 'filters', 'ids', 'sort', 'direction'])))], 202);
        }

        return $manager->download($resource, $request->user(), $request->input('format', 'xlsx'));
    }

    public function import(Request $request, string $resource, ImportManager $manager)
    {
        $request->validate(['file' => ['required', 'file'], 'options' => ['sometimes', 'array']]);
        try {
            $import = $manager->dispatch($resource, $request->file('file'), $request->user(), $request->input('options', []));
        } catch (FileValidationException $exception) {
            return response()->json(['message' => 'Invalid import file.', 'errors' => $exception->errors], 422);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new ImportResource($import))->response()->setStatusCode(202);
    }

    public function export(Request $request, string $resource, ExportManager $manager)
    {
        return response()->json(['data' => $this->exportData($manager->dispatch($resource, $request->user(), $request->all()))], 202);
    }

    public function showExport(Request $request, Export $export, DataAccess $access)
    {
        $access->authorizeExport($export, $request->user());

        return response()->json(['data' => $this->exportData($export)]);
    }

    public function download(Request $request, Export $export, ExportManager $manager)
    {
        return $manager->download($export, $request->user());
    }

    public function cancelExport(Request $request, Export $export, ExportManager $manager)
    {
        return response()->json(['data' => $this->exportData($manager->cancel($export, $request->user()))], 202);
    }

    public function retryExport(Request $request, Export $export, ExportManager $manager)
    {
        return response()->json(['data' => $this->exportData($manager->retry($export, $request->user()))], 202);
    }

    private function exportData(Export $export): array
    {
        return ['id' => $export->id, 'resource' => $export->resource, 'status' => $export->status->value, 'format' => $export->format,
            'total_rows' => $export->total_rows, 'processed_rows' => $export->processed_rows, 'percentage' => $export->percentage(),
            'filename' => $export->filename, 'download_available' => $export->status->value === 'completed' && $export->expires_at?->isFuture(),
            'expires_at' => $export->expires_at?->toIso8601String(), 'error_message' => $export->error_message];
    }
}
