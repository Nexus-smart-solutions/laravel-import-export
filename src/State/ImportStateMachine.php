<?php

namespace Nexus\ImportExport\State;

use Closure;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Exceptions\InvalidStateTransition;
use Nexus\ImportExport\Models\Import;

final class ImportStateMachine
{
    /** @var array<string, list<ImportStatus>> */
    private const TRANSITIONS = [
        'uploaded' => [ImportStatus::QUEUED, ImportStatus::CANCELLED, ImportStatus::FAILED],
        'queued' => [ImportStatus::VALIDATING, ImportStatus::CANCELLED, ImportStatus::FAILED],
        'validating' => [ImportStatus::PROCESSING, ImportStatus::CANCELLED, ImportStatus::FAILED],
        'processing' => [ImportStatus::FINALIZING, ImportStatus::CANCELLED, ImportStatus::FAILED],
        'finalizing' => [
            ImportStatus::COMPLETED,
            ImportStatus::COMPLETED_WITH_ERRORS,
            ImportStatus::FAILED,
            ImportStatus::CANCELLED,
        ],
        'failed' => [ImportStatus::QUEUED],
        'completed' => [],
        'completed_with_errors' => [],
        'cancelled' => [],
    ];

    public function can(ImportStatus $from, ImportStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /** @param array<string, mixed> $attributes */
    public function transition(
        Import $import,
        ImportStatus $to,
        array $attributes = [],
        ?Closure $constraint = null,
    ): Import {
        $from = $import->status;

        if (! $this->can($from, $to)) {
            throw new InvalidStateTransition("Import cannot transition from {$from->value} to {$to->value}.");
        }

        $query = Import::query()
            ->whereKey($import->getKey())
            ->where('status', $from->value);
        if ($constraint !== null) {
            $constraint($query);
        }
        $updated = $query->update([...$attributes, 'status' => $to->value, 'updated_at' => now()]);

        if ($updated !== 1) {
            throw new InvalidStateTransition('The import state changed concurrently.');
        }

        return $import->refresh();
    }
}
