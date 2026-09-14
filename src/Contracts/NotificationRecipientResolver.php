<?php

namespace Nexus\ImportExport\Contracts;

use Nexus\ImportExport\Models\Export;
use Nexus\ImportExport\Models\Import;

interface NotificationRecipientResolver
{
    /** Resolve only a stored creator or server-verified recipient, under the restored context. */
    public function resolve(Import|Export $operation): ?object;
}
