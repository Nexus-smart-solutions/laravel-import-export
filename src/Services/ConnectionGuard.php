<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Database\DatabaseManager;
use Nexus\ImportExport\Exceptions\ImportConfigurationException;

final class ConnectionGuard
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function assertMetadataAndTargetMatch(?string $targetConnection): string
    {
        $metadata = $this->database->connection(config('bulk-imports.database.connection'))->getName();
        $target = $this->database->connection($targetConnection)->getName();

        if ($metadata !== $target) {
            throw new ImportConfigurationException(
                "The transactional import pipeline requires import metadata [{$metadata}] and target rows [{$target}] "
                .'on the same connection. Use partial mode with a domain idempotency receipt/outbox for cross-database imports.',
            );
        }

        return $target;
    }
}
