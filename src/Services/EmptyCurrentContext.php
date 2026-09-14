<?php

namespace Nexus\ImportExport\Services;

use Nexus\ImportExport\Contracts\CurrentContext;

final class EmptyCurrentContext implements CurrentContext
{
    public function get(): array
    {
        return [];
    }
}
