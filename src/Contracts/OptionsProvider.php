<?php

namespace Nexus\ImportExport\Contracts;

interface OptionsProvider
{
    /** Return value => label; database providers must apply LIMIT $limit. */
    public function options(array $context, int $limit): iterable;
}
