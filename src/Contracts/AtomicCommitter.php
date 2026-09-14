<?php

namespace Nexus\ImportExport\Contracts;

use Nexus\ImportExport\Data\BatchWriteResult;
use Nexus\ImportExport\Data\ImportContext;
use Nexus\ImportExport\Definitions\ImportDefinition;
use Nexus\ImportExport\Models\Import;

interface AtomicCommitter
{
    public function connectionName(ImportDefinition $definition, ImportContext $context): ?string;

    /**
     * Commit all staged rows. The caller verifies that connectionName() is the
     * metadata connection, opens the transaction there, and includes the final
     * import state update in that same transaction.
     */
    public function commit(Import $import, ImportDefinition $definition, ImportContext $context): BatchWriteResult;
}
