<?php

namespace Nexus\ImportExport\Enums;

enum IdempotencyStrategy: string
{
    case REJECT = 'reject';
    case RETURN_EXISTING = 'return_existing';
    case ALLOW = 'allow';
}
