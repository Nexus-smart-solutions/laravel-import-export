<?php

namespace Nexus\ImportExport;

use Illuminate\Http\UploadedFile;
use Nexus\ImportExport\Contracts\ImportAuthorizer;
use Nexus\ImportExport\Enums\IdempotencyStrategy;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Services\CancelImport;
use Nexus\ImportExport\Services\DataAccess;
use Nexus\ImportExport\Services\ImportDispatcher;
use Nexus\ImportExport\Services\RetryImport;

final class ImportManager
{
    public function __construct(private readonly DataAccess $access, private readonly ImportDispatcher $dispatcher) {}

    public function dispatch(string $resource, UploadedFile $file, object $actor, array $options = [], ?IdempotencyStrategy $idempotency = null): Import
    {
        $definition = $this->access->definition($resource);
        $this->access->assertActor($definition, $actor, $resource);
        $this->access->check($definition->canImport($actor));

        return $this->dispatcher->dispatchDefinition($definition::class, $file, $actor, $definition->context(), $options, $idempotency);
    }

    public function retry(Import $import, object $actor): Import
    {
        $this->access->check(app(ImportAuthorizer::class)->canRetry($actor, $import));

        return app(RetryImport::class)->handle($import);
    }

    public function cancel(Import $import, object $actor): Import
    {
        $this->access->check(app(ImportAuthorizer::class)->canCancel($actor, $import));

        return app(CancelImport::class)->handle($import);
    }
}
