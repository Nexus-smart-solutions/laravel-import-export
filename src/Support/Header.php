<?php

namespace Nexus\ImportExport\Support;

final class Header
{
    public static function normalize(string $header): string
    {
        return mb_strtolower(trim(preg_replace('/[\s\p{Z}_]+/u', ' ', str_replace("\xEF\xBB\xBF", '', $header)) ?? $header));
    }
}
