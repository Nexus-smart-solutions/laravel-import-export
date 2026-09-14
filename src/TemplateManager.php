<?php

namespace Nexus\ImportExport;

use Nexus\ImportExport\Models\Export;
use Nexus\ImportExport\Services\DataAccess;
use Nexus\ImportExport\Templates\TemplateWriter;

final class TemplateManager
{
    public function __construct(private readonly DataAccess $access, private readonly TemplateWriter $writer) {}

    /** Caller owns this temporary file; download() removes it after sending. */
    public function generate(string $resource, object $actor, string $format = 'xlsx'): string
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, 'Use csv or xlsx.');
        $definition = $this->access->definition($resource);
        $this->access->assertActor($definition, $actor, $resource);
        $this->access->check($definition->canDownloadTemplate($actor));
        $path = tempnam(sys_get_temp_dir(), 'import-template-');
        if ($path === false) {
            throw new \RuntimeException('Cannot create template file.');
        }
        try {
            $this->writer->write($path, $definition, $format);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return $path;
    }

    public function download(string $resource, object $actor, string $format = 'xlsx')
    {
        return response()->download($this->generate($resource, $actor, $format), preg_replace('/[^A-Za-z0-9_-]/', '-', $resource).'-import-template.'.$format,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    public function withData(string $resource, object $actor, array $request = []): Export
    {
        $this->access->check($this->access->definition($resource)->canDownloadTemplate($actor));

        return app(ExportManager::class)->dispatch($resource, $actor, [...$request, 'compatible' => true]);
    }
}
