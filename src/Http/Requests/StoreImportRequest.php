<?php

namespace Nexus\ImportExport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(config('bulk-imports.definitions', [])))],
            'file' => [
                'required',
                'file',
                'max:'.(int) config('bulk-imports.limits.max_file_size_kb', 102400),
            ],
            'context' => ['sometimes', 'array'],
            'options' => ['sometimes', 'array'],
            'idempotency' => ['sometimes', Rule::in(['reject', 'return_existing', 'allow'])],
        ];
    }
}
