<?php

namespace Nexus\ImportExport\Contracts;

interface CurrentContext
{
    /** Trusted server-derived tenant context, never request input. */
    public function get(): array;
}
