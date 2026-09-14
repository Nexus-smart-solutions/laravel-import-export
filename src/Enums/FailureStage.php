<?php

namespace Nexus\ImportExport\Enums;

enum FailureStage: string
{
    case PREFLIGHT = 'preflight';
    case PREPARATION = 'preparation';
    case CHUNK = 'chunk';
    case FINALIZATION = 'finalization';
    case REPORT = 'report';
}
