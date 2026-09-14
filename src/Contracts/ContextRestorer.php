<?php

namespace Nexus\ImportExport\Contracts;

use Closure;

interface ContextRestorer
{
    /**
     * Restore application/tenant state, run the callback, then tear the state down.
     *
     * @param  array<string, scalar|null>  $context
     */
    public function run(array $context, Closure $callback): mixed;
}
