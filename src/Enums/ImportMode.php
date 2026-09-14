<?php

namespace Nexus\ImportExport\Enums;

enum ImportMode: string
{
    case PARTIAL = 'partial';
    case ATOMIC = 'atomic';
}
