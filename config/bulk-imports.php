<?php

use Nexus\ImportExport\Contracts\ContextRestorer;
use Nexus\ImportExport\Contracts\ImportAuthorizer;
use Nexus\ImportExport\Services\ActorImportAuthorizer;
use Nexus\ImportExport\Services\NullContextRestorer;

return [
    /*
    | Register only trusted, application-owned definition classes here.
    | Request input is resolved through this map and is never treated as a class name.
    */
    'definitions' => [
        // 'students' => App\Imports\StudentsImport::class,
    ],

    // Additional trusted SourceReader implementations, resolved from the container.
    'readers' => [
        // App\Imports\Readers\JsonLinesReader::class,
    ],

    'chunk_size' => 1000,
    'lookup_batch_size' => 500,
    'write_batch_size' => 1000,

    'queue' => [
        'connection' => null,
        'name' => 'imports',
        // Keep false in production: sync would parse rows inside the HTTP request.
        'allow_sync' => false,
        'tries' => 3,
        'prepare_timeout' => 600,
        'chunk_timeout' => 120,
        'finalizer_timeout' => 900,
        'report_timeout' => 120,
        'backoff' => [10, 30, 90],
        'prepare_lease_seconds' => 660,
        'chunk_lease_seconds' => 180,
        'finalizer_lease_seconds' => 960,
        'recovery_grace_seconds' => 300,
    ],

    'files' => [
        'disk' => env('FILESYSTEM_DISK', 'local'),
        'directory' => 'bulk-imports',
        'visibility' => 'private',
        'retention_days' => 7,
        'allowed_extensions' => ['csv', 'xlsx'],
        'allowed_mime_types' => [
            'text/csv',
            'text/plain',
            'application/csv',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ],
    ],

    'failures' => [
        'retention_days' => 30,
        'records_retention_days' => 30,
        'store_original_row_data' => true,
        'redact_columns' => ['password', 'password_confirmation', 'token', 'secret'],
        'max_value_length' => 1000,
        'max_row_bytes' => 65536,
        'write_batch_size' => 250,
        'formula_safe_csv' => true,
    ],

    'limits' => [
        'max_file_size_kb' => 1048576,
        'max_rows' => 10000000,
        'max_csv_record_bytes' => 2097152,
        'min_chunk_size' => 1,
        'max_chunk_size' => 5000,
        'max_columns' => 250,
        'max_cell_length' => 65535,
        'xlsx_max_compression_ratio' => 100,
        'xlsx_max_uncompressed_bytes' => 4294967296,
        'xlsx_max_archive_entries' => 10000,
    ],

    'idempotency' => [
        'default_strategy' => 'return_existing',
        'include_options' => true,
        // Prevent one actor's replay from returning another actor's protected import.
        'include_actor' => true,
    ],

    'database' => [
        // null means the currently active default connection (tenant-aware if restored).
        'connection' => null,
        'tables' => [
            'imports' => 'imports',
            'chunks' => 'import_chunks',
            'failures' => 'import_failures',
            'row_keys' => 'import_row_keys',
            'staged_rows' => 'import_staged_rows',
            'key_locks' => 'import_key_locks',
        ],
    ],

    'context' => [
        'restorer' => NullContextRestorer::class,
        'allowed_keys' => ['tenant_id', 'organization_id', 'workspace_id'],
        'max_bytes' => 4096,
    ],

    'authorization' => [
        'authorizer' => ActorImportAuthorizer::class,
        'require_actor' => true,
    ],

    'routes' => [
        'enabled' => false,
        'prefix' => 'api/imports',
        'middleware' => ['api', 'auth'],
    ],

    'atomic' => [
        'staging_batch_size' => 1000,
        'cleanup_staging_after_completion' => true,
    ],

    'contracts' => [
        ContextRestorer::class => NullContextRestorer::class,
        ImportAuthorizer::class => ActorImportAuthorizer::class,
    ],
];
