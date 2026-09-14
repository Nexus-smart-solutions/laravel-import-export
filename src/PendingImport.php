<?php

namespace Nexus\ImportExport;

use Illuminate\Http\UploadedFile;
use Nexus\ImportExport\Enums\IdempotencyStrategy;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Services\ImportDispatcher;

final class PendingImport
{
    private ?UploadedFile $file = null;

    private ?object $actor = null;

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array<string, mixed> */
    private array $options = [];

    private ?IdempotencyStrategy $idempotency = null;

    public function __construct(private readonly string $definitionClass) {}

    public function file(UploadedFile $file): self
    {
        $this->file = $file;

        return $this;
    }

    public function by(?object $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    /** @param array<string, mixed> $context */
    public function context(array $context): self
    {
        $this->context = $context;

        return $this;
    }

    /** @param array<string, mixed> $options */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function idempotency(IdempotencyStrategy|string $strategy): self
    {
        $this->idempotency = is_string($strategy) ? IdempotencyStrategy::from($strategy) : $strategy;

        return $this;
    }

    public function dispatch(): Import
    {
        if ($this->file === null) {
            throw new \LogicException('An uploaded file is required before dispatching an import.');
        }

        return app(ImportDispatcher::class)->dispatchDefinition(
            $this->definitionClass,
            $this->file,
            $this->actor,
            $this->context,
            $this->options,
            $this->idempotency,
        );
    }
}
