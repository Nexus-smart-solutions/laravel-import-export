<?php

namespace Nexus\ImportExport\Services;

use Closure;
use Nexus\ImportExport\Contracts\ContextRestorer;

final class NullContextRestorer implements ContextRestorer
{
    public function run(array $context, Closure $callback): mixed
    {
        return $callback();
    }
}
