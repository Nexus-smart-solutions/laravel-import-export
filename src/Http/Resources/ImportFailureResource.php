<?php

namespace Nexus\ImportExport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nexus\ImportExport\Models\ImportFailure;

/** @mixin ImportFailure */
final class ImportFailureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rowNumber' => $this->row_number,
            'sheet' => $this->sheet, 'sheetRow' => $this->sheet_row, 'failureType' => $this->failure_type,
            'normalizedRowData' => $this->normalized_row_data,
            'column' => $this->column,
            'errorCode' => $this->error_code,
            'errorMessage' => $this->error_message,
            'originalRowData' => $this->original_row_data,
            'redacted' => $this->redacted,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
