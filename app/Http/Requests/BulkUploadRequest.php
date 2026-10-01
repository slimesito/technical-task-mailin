<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.config('domain_checks.bulk.max_file_kb')],
            'dkim_selector' => ['nullable', 'string', 'max:63', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }
}
