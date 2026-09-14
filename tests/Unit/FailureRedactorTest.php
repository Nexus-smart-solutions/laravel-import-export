<?php

namespace Nexus\ImportExport\Tests\Unit;

use Nexus\ImportExport\Services\FailureRedactor;
use Nexus\ImportExport\Tests\TestCase;

final class FailureRedactorTest extends TestCase
{
    public function test_sensitive_columns_are_removed_before_failure_persistence(): void
    {
        $result = app(FailureRedactor::class)->redact([
            'email' => 'student@example.test',
            'password' => 'plaintext-secret',
            'token' => 'api-token',
        ]);

        self::assertTrue($result['redacted']);
        self::assertSame('[REDACTED]', $result['row']['password']);
        self::assertSame('[REDACTED]', $result['row']['token']);
        self::assertSame('student@example.test', $result['row']['email']);
    }

    public function test_oversized_failure_rows_are_replaced_with_a_bounded_marker(): void
    {
        config()->set('bulk-imports.failures.max_row_bytes', 32);

        $result = app(FailureRedactor::class)->redact(['notes' => str_repeat('x', 100)]);

        self::assertTrue($result['redacted']);
        self::assertArrayHasKey('_redacted', $result['row']);
    }
}
