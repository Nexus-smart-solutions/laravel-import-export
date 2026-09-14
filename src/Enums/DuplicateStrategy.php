<?php

namespace Nexus\ImportExport\Enums;

enum DuplicateStrategy: string
{
    /** Existing business keys are row failures. */
    case ERROR = 'error';

    /** Existing business keys are counted as skipped. */
    case SKIP = 'skip';

    /** Only existing business keys are updated; missing keys are skipped. */
    case UPDATE = 'update';

    /** Missing business keys are inserted and existing keys are updated. */
    case UPSERT = 'upsert';
}
