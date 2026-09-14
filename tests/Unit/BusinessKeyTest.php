<?php

namespace Nexus\ImportExport\Tests\Unit;

use Nexus\ImportExport\Support\BusinessKey;
use PHPUnit\Framework\TestCase;

final class BusinessKeyTest extends TestCase
{
    public function test_hash_is_order_stable_without_silently_changing_key_values(): void
    {
        $first = BusinessKey::hash(['tenant' => 4, 'code' => 'STU-1'], ['tenant', 'code']);
        $second = BusinessKey::hash(['code' => 'STU-1', 'tenant' => 4], ['tenant', 'code']);

        self::assertSame($first, $second);
        self::assertNotSame(
            $first,
            BusinessKey::hash(['tenant' => 4, 'code' => ' STU-1 '], ['tenant', 'code']),
        );
    }
}
