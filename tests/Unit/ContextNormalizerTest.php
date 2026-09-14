<?php

namespace Nexus\ImportExport\Tests\Unit;

use InvalidArgumentException;
use Nexus\ImportExport\Services\ContextNormalizer;
use Nexus\ImportExport\Tests\TestCase;

final class ContextNormalizerTest extends TestCase
{
    public function test_it_accepts_whitelisted_scalar_context_and_sorts_it(): void
    {
        $context = app(ContextNormalizer::class)->normalize(['workspace_id' => 'w-1', 'tenant_id' => 9]);

        self::assertSame(['tenant_id' => 9, 'workspace_id' => 'w-1'], $context);
    }

    public function test_it_rejects_untrusted_context_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ContextNormalizer::class)->normalize(['database_password' => 'secret']);
    }

    public function test_it_rejects_non_finite_context_numbers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ContextNormalizer::class)->normalize(['tenant_id' => INF]);
    }
}
