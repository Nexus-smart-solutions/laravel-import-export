<?php

use Nexus\ImportExport\Notifications\CreatorRecipientResolver;
use Nexus\ImportExport\Notifications\OperationFinished;
use Nexus\ImportExport\Services\EmptyCurrentContext;

return [
    'current_context' => EmptyCurrentContext::class,
    'authentication_guard' => null,
    'guests' => ['enabled' => false, 'ttl_seconds' => 86400, 'retention_days' => 7],
    'notifications' => ['enabled' => false, 'channels' => ['database'], 'guest_channels' => ['mail'],
        'queue' => 'notifications', 'connection' => null, 'retention_days' => 30, 'tries' => 3, 'timeout' => 60,
        'lease_seconds' => 120, 'backoff' => [30, 150, 300],
        'notification_class' => OperationFinished::class,
        'recipient_resolver' => CreatorRecipientResolver::class],
    'options_limit' => 5000,
    'chunk_max_bytes' => 16 * 1024 * 1024,
    'csv' => ['delimiter' => ',', 'bom' => true, 'line_ending' => "\r\n", 'formula_safe' => true],
    'xlsx' => ['rows_per_sheet' => 1048576],
    'templates' => ['dropdown_limit' => 5000, 'total_option_cells' => 20000, 'sample_limit' => 100, 'validation_rows' => 10000],
    'exports' => [
        'disk' => null, // Falls back to bulk-imports.files.disk; all workers must share this disk.
        'directory' => 'bulk-exports',
        'query_chunk_size' => 2000,
        'selected_ids_limit' => 1000,
        'retention_days' => 7,
        'queue' => 'exports',
        'connection' => null,
        'timeout' => 3600,
        'lease_seconds' => 3660,
        'tries' => 3,
        'backoff' => [10, 30, 90],
        'allow_sync' => false,
    ],
    'routes' => ['enabled' => false, 'prefix' => 'api/data', 'middleware' => ['api']],
];
