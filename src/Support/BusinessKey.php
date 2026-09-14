<?php

namespace Nexus\ImportExport\Support;

final class BusinessKey
{
    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     */
    public static function hash(array $row, array $columns): string
    {
        $values = [];

        foreach ($columns as $column) {
            $values[$column] = $row[$column] ?? null;
        }

        return hash('sha256', CanonicalJson::encode($values));
    }

    /** @return array<string, mixed> */
    public static function values(array $row, array $columns): array
    {
        return array_intersect_key($row, array_flip($columns));
    }
}
