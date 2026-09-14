<?php

namespace Nexus\ImportExport\Services;

final class FailureRedactor
{
    /** @param array<string,mixed> $row @return array{row:?array,redacted:bool} */
    public function redact(array $row): array
    {
        if (! config('bulk-imports.failures.store_original_row_data', true)) {
            return ['row' => null, 'redacted' => true];
        }

        $redactedColumns = array_map('strtolower', config('bulk-imports.failures.redact_columns', []));
        $maxLength = (int) config('bulk-imports.failures.max_value_length', 1000);
        $didRedact = false;

        foreach ($row as $column => &$value) {
            if (in_array(strtolower((string) $column), $redactedColumns, true)) {
                $value = '[REDACTED]';
                $didRedact = true;

                continue;
            }

            if (is_string($value) && strlen($value) > $maxLength) {
                $value = mb_substr($value, 0, $maxLength).'…';
                $didRedact = true;
            }
        }
        unset($value);

        $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > (int) config('bulk-imports.failures.max_row_bytes', 65536)) {
            $row = ['_redacted' => 'Original row exceeded the configured failure-storage limit.'];
            $didRedact = true;
        }

        return ['row' => $row, 'redacted' => $didRedact];
    }
}
